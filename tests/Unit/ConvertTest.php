<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Convert;
use App\Pandoc;
use App\PandocException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use SimpleXMLElement;

#[CoversClass(Convert::class)]
final class ConvertTest extends TestCase
{
    private vfsStreamDirectory $fileSystem;

    /** Project root, captured so tearDown can prove no real files were created. */
    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        $this->fileSystem = vfsStream::setup('root', null, [
            'data' => [
                'valid.xml' => $this->sampleXml(),
                'invalid.xml' => '<not><valid></xml>',
                'empty.xml' => '<mediawiki></mediawiki>',
            ],
            'output' => [],
        ]);
    }

    /**
     * Safety net: every test must operate entirely inside the vfsStream sandbox.
     * If a test ever writes to the real project tree, fail loudly here rather than
     * silently leaving (or deleting) files on disk.
     */
    protected function tearDown(): void
    {
        $strays = glob(self::$projectRoot . '/output/*');
        $this->assertSame(
            [],
            $strays ?: [],
            'A test wrote to the real project output/ directory: ' . implode(', ', $strays ?: []),
        );
    }

    /**
     * Build a Convert instance whose Pandoc dependency is mocked so no binary is invoked.
     *
     * @param array<string, mixed> $options
     */
    private function makeConvert(array $options = [], ?Pandoc $pandoc = null): Convert
    {
        // Force every output path into the vfsStream sandbox. A bare relative
        // path (e.g. 'output') would otherwise resolve against the real CWD.
        if (!isset($options['output']) || !str_starts_with((string) $options['output'], 'vfs://')) {
            $options['output'] = $this->fileSystem->url() . '/output';
        }

        return new Convert($options, $pandoc ?? $this->makePandoc());
    }

    /**
     * A Pandoc test double that echoes its input back, standing in for a real conversion.
     *
     * We use a hand-written fake rather than a PHPUnit mock on purpose: the real
     * Pandoc::__destruct() runs glob($tmpFile.'*') and unlinks the matches, and a
     * mock created without the constructor leaves $tmpFile empty, so glob('*') would
     * match (and delete) every file in the current working directory when the mock is
     * garbage-collected. FakePandoc has an empty destructor and never touches disk.
     */
    private function makePandoc(): Pandoc
    {
        return new FakePandoc();
    }

    private function makeThrowingPandoc(string $message = 'boom'): Pandoc
    {
        return new FakePandoc(throwMessage: $message);
    }

    private function urlFor(string $file): string
    {
        return $this->fileSystem->url() . '/data/' . $file;
    }

    /** The sandboxed output directory, with the trailing slash Convert appends. */
    private function outputUrl(): string
    {
        return $this->fileSystem->url() . '/output/';
    }

    // --- Option parsing -----------------------------------------------------

    #[Test]
    public function it_sets_and_reads_a_string_option(): void
    {
        $convert = $this->makeConvert();
        $convert->setOption('format', ['format' => 'rst']);

        $this->assertSame('rst', $convert->getOption('format'));
    }

    #[Test]
    public function a_bare_flag_with_no_value_is_treated_as_true(): void
    {
        $convert = $this->makeConvert();
        // getopt represents a value-less flag as an empty string.
        $convert->setOption('flatten', ['flatten' => '']);

        $this->assertTrue($convert->getOption('flatten'));
    }

    #[Test]
    public function an_absent_boolean_option_falls_back_to_its_default(): void
    {
        $convert = $this->makeConvert();
        $convert->setOption('addmeta', []);

        $this->assertFalse($convert->getOption('addmeta'));
    }

    #[Test]
    public function constructor_maps_all_arguments_onto_properties(): void
    {
        $convert = new Convert([
            'filename' => '/my/file/name.xml',
            'output' => 'newoutputfolder',
            'format' => 'testformat',
            'addmeta' => '',
            'flatten' => '',
            'indexes' => '',
            'skiperrors' => '',
        ], $this->makePandoc());

        $this->assertSame('/my/file/name.xml', $convert->getOption('filename'));
        $this->assertSame('newoutputfolder/', $convert->getOption('output'));
        $this->assertSame('testformat', $convert->getOption('format'));
        $this->assertTrue($convert->getOption('addmeta'));
        $this->assertTrue($convert->getOption('flatten'));
        $this->assertTrue($convert->getOption('indexes'));
        $this->assertTrue($convert->getOption('skiperrors'));
    }

    #[Test]
    public function output_default_is_normalized_with_a_trailing_slash(): void
    {
        $convert = new Convert([], $this->makePandoc());

        $this->assertSame('output/', $convert->getOption('output'));
    }

    // --- File metadata ------------------------------------------------------

    #[Test]
    public function it_builds_meta_for_a_single_level_page(): void
    {
        $convert = $this->makeConvert();
        $node = $this->firstPage();

        $this->assertSame([
            'directory' => $this->outputUrl(),
            'filename' => 'Pageone',
            'title' => 'Pageone',
            'url' => 'Pageone',
        ], $convert->retrieveFileInfo($node->xpath('title')));
    }

    #[Test]
    public function it_builds_meta_for_a_nested_page(): void
    {
        $convert = $this->makeConvert();
        $node = $this->secondPage();

        $this->assertSame([
            'directory' => $this->outputUrl() . 'Folderone/',
            'filename' => 'Pagetwo',
            'title' => 'Folderone Pagetwo',
            'url' => 'Folderone/Pagetwo',
        ], $convert->retrieveFileInfo($node->xpath('title')));
    }

    #[Test]
    public function flatten_collapses_a_nested_page_into_a_single_filename(): void
    {
        $convert = $this->makeConvert(['flatten' => '']);
        $node = $this->secondPage();
        $meta = $convert->retrieveFileInfo($node->xpath('title'));

        $this->assertSame($this->outputUrl(), $meta['directory']);
        $this->assertSame('Folderone_Pagetwo', $meta['filename']);
    }

    #[Test]
    #[DataProvider('metaDataProvider')]
    public function it_builds_permalink_front_matter_only_when_addmeta_is_set(
        bool $addmeta,
        string $expected,
    ): void {
        $convert = $this->makeConvert();
        $convert->setOption('addmeta', ['addmeta' => $addmeta ? '' : null]);

        $meta = ['title' => 'My file title', 'url' => 'my/url'];

        $this->assertSame($expected, $convert->getMetaData($meta));
    }

    /** @return array<string, array{bool, string}> */
    public static function metaDataProvider(): array
    {
        return [
            'disabled yields empty string' => [false, ''],
            'enabled yields front matter' => [
                true,
                "---\ntitle: My file title\npermalink: /my/url/\n---\n\n",
            ],
        ];
    }

    // --- De-duplication -----------------------------------------------------

    #[Test]
    public function repeated_filenames_get_an_incrementing_suffix(): void
    {
        $convert = $this->makeConvert();

        $this->assertSame('Page', $convert->pageDeDuplicator('Page'));
        $this->assertSame('Page(1)', $convert->pageDeDuplicator('Page'));
        $this->assertSame('Page(2)', $convert->pageDeDuplicator('Page'));
    }

    #[Test]
    public function de_duplication_is_case_insensitive(): void
    {
        $convert = $this->makeConvert();

        $this->assertSame('Page', $convert->pageDeDuplicator('Page'));
        $this->assertSame('page(1)', $convert->pageDeDuplicator('page'));
    }

    // --- Link cleaning (cleanText) -----------------------------------------

    #[Test]
    #[DataProvider('cleanTextProvider')]
    public function clean_text_rewrites_links_relative_to_the_current_page(
        string $input,
        string $expected,
    ): void {
        $convert = $this->makeConvert();
        $meta = $convert->retrieveFileInfo($this->secondPage()->xpath('title'));

        $this->assertSame($expected, $convert->cleanText($input, $meta));
    }

    /** @return array<string, array{string, string}> */
    public static function cleanTextProvider(): array
    {
        return [
            'normalizes a ../../ path' => [
                '[[../../minutes|can be found here]]',
                '[[minutes|can be found here]]',
            ],
            'resolves a /-rooted relative path against the page url' => [
                '[[/minutes|can be found here]]',
                '[[Folderone/Pagetwo/minutes|can be found here]]',
            ],
            'passes a malformed external link through as a plain link' => [
                '[[https://minutes can be found here]]',
                '[https://minutes can be found here]',
            ],
        ];
    }

    #[Test]
    public function clean_text_decodes_html_entities(): void
    {
        $convert = $this->makeConvert();
        $meta = ['url' => 'Page'];

        $this->assertSame('a & b < c', $convert->cleanText('a &amp; b &lt; c', $meta));
    }

    // --- Pandoc interaction -------------------------------------------------

    #[Test]
    public function run_pandoc_unescapes_backslashed_underscores(): void
    {
        $pandoc = new FakePandoc(returnValue: 'some \_text\_');

        $convert = $this->makeConvert([], $pandoc);
        $convert->pandocSetup();

        $this->assertSame('some _text_', $convert->runPandoc('some _text_'));
        $this->assertSame('some _text_', $pandoc->lastContent);
        $this->assertSame(['from' => 'mediawiki', 'to' => 'gfm'], $pandoc->lastOptions);
    }

    #[Test]
    public function pandoc_setup_forwards_the_chosen_format(): void
    {
        $pandoc = new FakePandoc();

        $convert = $this->makeConvert(['format' => 'rst'], $pandoc);
        $convert->pandocSetup();
        $convert->runPandoc('x');

        $this->assertSame(['from' => 'mediawiki', 'to' => 'rst'], $pandoc->lastOptions);
    }

    // --- Full conversion pipeline ------------------------------------------

    #[Test]
    public function convert_data_writes_a_markdown_file_per_page(): void
    {
        $convert = $this->makeConvert(['filename' => $this->urlFor('valid.xml')]);
        $convert->pandocSetup();
        $convert->loadData($convert->loadFile());
        $convert->convertData();

        $output = $this->fileSystem->getChild('output');
        $this->assertTrue($output->hasChild('Pageone.md'));
        $this->assertTrue($output->hasChild('Folderone'));
        $this->assertTrue($output->getChild('Folderone')->hasChild('Pagetwo.md'));
    }

    #[Test]
    public function convert_data_aborts_on_a_pandoc_error_by_default(): void
    {
        $pandoc = $this->makeThrowingPandoc();

        $convert = $this->makeConvert(['filename' => $this->urlFor('valid.xml')], $pandoc);
        $convert->pandocSetup();
        $convert->loadData($convert->loadFile());

        $this->expectException(\RuntimeException::class);
        $convert->convertData();
    }

    #[Test]
    public function convert_data_skips_failing_pages_when_skiperrors_is_set(): void
    {
        $pandoc = $this->makeThrowingPandoc();

        $convert = $this->makeConvert([
            'filename' => $this->urlFor('valid.xml'),
            'skiperrors' => '',
        ], $pandoc);
        $convert->pandocSetup();
        $convert->loadData($convert->loadFile());

        $this->expectOutputRegex('/Failed converting/');
        $convert->convertData();

        // Nothing should have been written.
        $this->assertCount(0, $this->fileSystem->getChild('output')->getChildren());
    }

    #[Test]
    public function index_pages_are_renamed_when_indexes_is_set(): void
    {
        $xml = <<<XML
        <mediawiki ns="http://www.mediawiki.org/xml/export-0.10/" version="0.10">
          <page><title>Folderone</title><revision><text>landing</text></revision></page>
          <page><title>Folderone/Child</title><revision><text>child</text></revision></page>
        </mediawiki>
        XML;
        vfsStream::newFile('data/indexes.xml')->at($this->fileSystem)->setContent($xml);

        $convert = $this->makeConvert([
            'filename' => $this->urlFor('indexes.xml'),
            'indexes' => '',
        ]);
        $convert->pandocSetup();
        $convert->loadData($convert->loadFile());
        $convert->convertData();
        $convert->renameFiles();

        $folder = $this->fileSystem->getChild('output')->getChild('Folderone');
        $this->assertTrue($folder->hasChild('index.md'));
        $this->assertTrue($folder->hasChild('Child.md'));
        $this->assertFalse($this->fileSystem->getChild('output')->hasChild('Folderone.md'));
    }

    // --- XML loading --------------------------------------------------------

    #[Test]
    public function it_loads_page_nodes_from_a_valid_export(): void
    {
        $convert = $this->makeConvert(['filename' => $this->urlFor('valid.xml')]);
        $convert->loadData($convert->loadFile());

        $pages = $convert->getOption('dataToConvert');
        $this->assertCount(2, $pages);
        $this->assertSame('Pageone', (string) $pages[0]->title);
        $this->assertSame('Folderone/Pagetwo', (string) $pages[1]->title);
    }

    #[Test]
    public function load_file_strips_the_xml_namespace_declaration(): void
    {
        $convert = $this->makeConvert(['filename' => $this->urlFor('valid.xml')]);

        $this->assertStringNotContainsString('xmlns=', $convert->loadFile());
    }

    #[Test]
    public function loading_invalid_xml_throws(): void
    {
        $convert = $this->makeConvert(['filename' => $this->urlFor('invalid.xml')]);

        $this->expectException(\RuntimeException::class);
        $convert->loadData($convert->loadFile());
    }

    #[Test]
    public function loading_an_export_without_pages_throws(): void
    {
        $convert = $this->makeConvert(['filename' => $this->urlFor('empty.xml')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('XML Data is empty');
        $convert->loadData($convert->loadFile());
    }

    #[Test]
    public function loading_a_missing_file_throws(): void
    {
        $convert = $this->makeConvert(['filename' => $this->urlFor('nope.xml')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Input file does not exist');
        $convert->loadFile();
    }

    // --- Directory creation -------------------------------------------------

    #[Test]
    public function it_creates_a_missing_output_directory(): void
    {
        $convert = $this->makeConvert();
        $dir = $this->fileSystem->url() . '/freshdir';

        $convert->createDirectory($dir);

        $this->assertDirectoryExists($dir);
    }

    #[Test]
    public function creating_a_directory_is_idempotent(): void
    {
        $convert = $this->makeConvert();
        $existing = $this->fileSystem->url() . '/output';

        // Should not throw when the directory already exists.
        $this->assertSame($existing, $convert->createDirectory($existing));
    }

    // --- CLI output ---------------------------------------------------------

    #[Test]
    public function get_version_prints_a_version_string(): void
    {
        $this->expectOutputRegex('/Version:/');
        $this->makeConvert()->getVersion();
    }

    #[Test]
    public function help_prints_usage_including_the_license(): void
    {
        $this->expectOutputRegex('/MIT License/');
        $this->makeConvert()->help();
    }

    // --- Fixtures -----------------------------------------------------------

    private function firstPage(): SimpleXMLElement
    {
        return $this->pages()[0];
    }

    private function secondPage(): SimpleXMLElement
    {
        return $this->pages()[1];
    }

    /** @return array<int, SimpleXMLElement> */
    private function pages(): array
    {
        $xml = new SimpleXMLElement(str_replace('xmlns=', 'ns=', $this->sampleXml()));

        return $xml->xpath('page');
    }

    private function sampleXml(): string
    {
        return <<<XMLFILE
        <mediawiki xmlns="http://www.mediawiki.org/xml/export-0.10/" version="0.10" xml:lang="en">
          <page>
            <title>Pageone</title>
            <revision>
              <text xml:space="preserve">This is a page with [[Folderone/Documentone|a link]].</text>
            </revision>
          </page>
          <page>
            <title>Folderone/Pagetwo</title>
            <revision>
              <text xml:space="preserve">=== Attendance ===

        [http://domain.com/recording/file.mp3 Audio Recording]</text>
            </revision>
          </page>
        </mediawiki>
        XMLFILE;
    }
}

/**
 * Hand-written Pandoc test double.
 *
 * It deliberately does NOT call the real Pandoc constructor (which requires a
 * pandoc binary and a writable temp dir) and overrides __destruct() to do nothing,
 * so the fake never invokes the binary or touches the filesystem.
 */
final class FakePandoc extends Pandoc
{
    public ?string $lastContent = null;

    /** @var array<string, string|null>|null */
    public ?array $lastOptions = null;

    public function __construct(
        private string $returnValue = '',
        private ?string $throwMessage = null,
    ) {
        // Intentionally do not call parent::__construct().
    }

    /**
     * @param array<string, string|null> $options
     */
    public function runWith(string $content, array $options): string
    {
        $this->lastContent = $content;
        $this->lastOptions = $options;

        if ($this->throwMessage !== null) {
            throw new PandocException($this->throwMessage);
        }

        // Default behaviour echoes the input back, mimicking a pass-through conversion.
        return $this->returnValue !== '' ? $this->returnValue : $content;
    }

    public function __destruct()
    {
        // No-op: never glob/unlink anything.
    }
}
