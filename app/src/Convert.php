<?php

declare(strict_types=1);

namespace App;

use SimpleXMLElement;

class Convert
{
    /**
     * Converter version.
     */
    private string $version = '1.0.1';

    /**
     * Path and name of the file to convert.
     */
    private ?string $filename = null;

    /**
     * Path to the directory where converted files are saved.
     */
    private string $output = 'output';

    /**
     * When true, converted files are saved in a single directory level.
     */
    private bool $flatten = false;

    /**
     * When true, a permalink front-matter block is added to each converted file.
     */
    private bool $addmeta = false;

    /**
     * When true, a file matching the name of its directory is renamed to index.md.
     */
    private bool $indexes = false;

    /**
     * When true, files that fail conversion are skipped rather than aborting the run.
     */
    private bool $skiperrors = false;

    /**
     * Target format passed to Pandoc.
     */
    private string $format = 'gfm';

    /**
     * Count of files converted so far.
     */
    private int $counter = 0;

    /**
     * Directories seen while converting, used by renameFiles() when --indexes is set.
     *
     * @var list<string>
     */
    private array $directory_list = [];

    /**
     * Page nodes extracted from the XML export.
     *
     * @var array<int, SimpleXMLElement>
     */
    private array $dataToConvert = [];

    /**
     * Options forwarded to Pandoc on each conversion.
     *
     * @var array<string, string>
     */
    private array $pandocOptions = [];

    /**
     * Space-delimited lower-cased list of filenames already written, used to de-duplicate.
     */
    private string $pageList = '';

    /**
     * @param array<string, mixed> $options Parsed CLI options (from getopt).
     * @param Pandoc|null $pandoc Injected Pandoc instance; a real one is created when null.
     */
    public function __construct(array $options, private ?Pandoc $pandoc = null)
    {
        $this->setArguments($options);
    }

    public function run(): void
    {
        $this->createDirectory($this->output);
        $this->pandocSetup();
        $this->loadData($this->loadFile());
        $this->convertData();
        $this->renameFiles();
        $this->message("{$this->counter} files converted");
    }

    /**
     * Create the Pandoc instance (if not injected) and build the conversion options.
     */
    public function pandocSetup(): void
    {
        $this->pandoc ??= new Pandoc();
        $this->pandocOptions = [
            'from' => 'mediawiki',
            'to' => $this->format,
        ];
    }

    /**
     * Clean, convert and write every page found in the XML export.
     */
    public function convertData(): void
    {
        foreach ($this->dataToConvert as $node) {
            $fileMeta = $this->retrieveFileInfo($node->xpath('title'));
            $text = $node->xpath('revision/text');
            $text = $this->cleanText((string) $text[0], $fileMeta);

            try {
                $text = $this->runPandoc($text);
                $output = $this->getMetaData($fileMeta) . $text;
                $this->saveFile($fileMeta, $output);
                $this->counter++;
            } catch (PandocException $e) {
                if (!$this->skiperrors) {
                    throw new \RuntimeException($e->getMessage(), 0, $e);
                }

                $this->message("Failed converting {$fileMeta['title']}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Decode entities and rewrite wiki links before handing text to Pandoc.
     *
     * @param array<string, string> $fileMeta
     */
    public function cleanText(string $text, array $fileMeta): string
    {
        $callback = new CleanLink($this->flatten, $fileMeta);

        // Decode inline HTML entities so Pandoc sees the real characters.
        $text = html_entity_decode($text);

        // Rewrite [[wiki links]] into clean, normalized links.
        return preg_replace_callback('/\[\[(.+?)\]\]/', $callback->cleanLink(...), $text);
    }

    /**
     * Run Pandoc and unescape underscores it would otherwise backslash-escape.
     */
    public function runPandoc(string $text): string
    {
        $text = $this->pandoc->runWith($text, $this->pandocOptions);

        return str_replace('\_', '_', $text);
    }

    /**
     * Write the converted markdown to disk.
     *
     * @param array<string, string> $fileMeta
     */
    public function saveFile(array $fileMeta, string $text): void
    {
        $this->createDirectory($fileMeta['directory']);

        $fileName = $this->pageDeDuplicator($fileMeta['filename']);

        file_put_contents($fileMeta['directory'] . $fileName . '.md', $text);

        $this->message("Converted: {$fileMeta['directory']}{$fileName}");
    }

    /**
     * Append a counter suffix when a filename (case-insensitively) has already been written.
     */
    public function pageDeDuplicator(string $filename): string
    {
        $lcFileName = strtolower($filename);
        $count = substr_count($this->pageList, " {$lcFileName} ");

        $this->pushPage($lcFileName);

        return $count ? "{$filename}({$count})" : $filename;
    }

    /**
     * Record a filename on the de-duplication list.
     */
    public function pushPage(string $file): void
    {
        $this->pageList .= ' ' . strtolower($file) . ' ';
    }

    /**
     * Build directory, filename, title and url for a page from its title node.
     *
     * @param array<int, SimpleXMLElement> $title
     * @return array<string, string>
     */
    public function retrieveFileInfo(array $title): array
    {
        $title = (string) $title[0];
        $url = str_replace(' ', '_', $title);
        $filename = $url;
        $directory = '';

        if (strpos($url, '/')) {
            $title = str_replace('/', ' ', $title);
            $url_parts = pathinfo($url);
            $directory = $url_parts['dirname'];
            $filename = $url_parts['basename']; // Avoids breaking names with periods in them.
            $this->directory_list[] = $directory;
            if ($this->flatten && $directory !== '') {
                $filename = str_replace('/', '_', $directory) . '_' . $filename;
                $directory = '';
            } else {
                $directory = rtrim($directory, '/') . '/';
            }
        }
        $directory = $this->output . $directory;

        return [
            'directory' => $directory,
            'filename' => $filename,
            'title' => $title,
            'url' => $url,
        ];
    }

    /**
     * Output a message to the CLI.
     */
    public function message(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Rename files that share the name of a folder to index.md (only when --indexes is set).
     */
    public function renameFiles(): void
    {
        if ($this->flatten || $this->directory_list === [] || !$this->indexes) {
            return;
        }

        foreach ($this->directory_list as $directory_name) {
            if (file_exists($this->output . $directory_name . '.md')) {
                rename(
                    $this->output . $directory_name . '.md',
                    $this->output . $directory_name . '/index.md'
                );
            }
        }
    }

    /**
     * Build the permalink front-matter block, or an empty string when --addmeta is unset.
     *
     * @param array<string, string> $fileMeta
     */
    public function getMetaData(array $fileMeta): string
    {
        return $this->addmeta
            ? sprintf("---\ntitle: %s\npermalink: /%s/\n---\n\n", $fileMeta['title'], $fileMeta['url'])
            : '';
    }

    /**
     * Read the XML export, normalizing xmlns= to ns= so SimpleXML xpath works.
     */
    public function loadFile(): string
    {
        if ($this->filename === null || !file_exists($this->filename)) {
            throw new \RuntimeException('Input file does not exist: ' . ($this->filename ?? ''));
        }

        $file = file_get_contents($this->filename);

        return str_replace('xmlns=', 'ns=', $file);
    }

    /**
     * Parse the XML and extract the page nodes to convert.
     */
    public function loadData(string $xmlData): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = new SimpleXMLElement($xmlData, LIBXML_PARSEHUGE);
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid XML File.', 0, $e);
        } finally {
            libxml_use_internal_errors($previous);
        }

        $this->dataToConvert = $xml->xpath('page');

        if ($this->dataToConvert === []) {
            throw new \RuntimeException('XML Data is empty');
        }
    }

    /**
     * Map parsed CLI options onto the converter's properties.
     *
     * @param array<string, mixed> $options
     */
    public function setArguments(array $options): void
    {
        $this->setOption('filename', $options, null);
        $this->setOption('output', $options, 'output');
        $this->setOption('format', $options, 'gfm');
        $this->setOption('flatten', $options);
        $this->setOption('indexes', $options);
        $this->setOption('addmeta', $options);
        $this->setOption('skiperrors', $options);
        $this->output = rtrim($this->output, '/') . '/';
    }

    /**
     * Set one option, treating a present-but-empty value (a bare flag) as true.
     *
     * @param array<string, mixed> $options
     */
    public function setOption(string $name, array $options, mixed $default = false): void
    {
        if (!isset($options[$name])) {
            $this->{$name} = $default;

            return;
        }

        $this->{$name} = empty($options[$name]) ? true : $options[$name];
    }

    /**
     * Get a single option/property value.
     */
    public function getOption(string $name): mixed
    {
        return $this->{$name};
    }

    public function getVersion(): void
    {
        $this->message("Version: {$this->version}");
    }

    /**
     * Create a directory (recursively) when it does not already exist.
     */
    public function createDirectory(?string $directory = null): ?string
    {
        if (!empty($directory) && !file_exists($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create directory: ' . $directory);
            }
        }

        return $directory;
    }

    public function help(): void
    {
        $helpMessage = <<<HELPMESSAGE
        Version: {$this->version}
        MIT License: https://opensource.org/licenses/MIT

        Mediawiki to GFM converter is a script that will convert a set of media wiki
        files to GitHub Flavoured Markdown (GFM).

        Requirements:
            pandoc: Installation instructions are here https://pandoc.org/installing.html
                    Tested on version 3.9 and up.
            mediawiki: https://www.mediawiki.org/wiki/MediaWiki

        Run the script on your exported MediaWiki XML file:
            ./convert.php --filename=/path/to/filename.xml

        Options:
            ./convert.php --filename=/path/to/filename.xml --output=/path/to/converted/files --format=gfm --addmeta --flatten --indexes

            --filename   : Location of the mediawiki exported XML file to convert to GFM format (Required).
            --output     : Location where you would like to save the converted files (Default: ./output).
            --format     : What format would you like to convert to. Default is GFM (for use
                           in GitLab and GitHub) See pandoc documentation for more formats (Default: 'gfm').
            --addmeta    : This flag will add a Permalink to each file (Default: false).
            --flatten    : This flag will force all pages to be saved in a single level
                           directory. File names will be converted in the following way:
                           Mediawiki_folder/My_File_Name -> Mediawiki_folder_My_File_Name
                           and saved in a file called 'Mediawiki_folder_My_File_Name.md'.
            --indexes    : Rename a file matching its directory name to index.md.
            --skiperrors : Do not stop on pandoc parsing errors, instead, skip the file (Default: false).
            --version    : Displays the program's version.
            --help       : This help message.

        Export Mediawiki Files to XML
        In order to convert from MediaWiki format to GFM and use in GitLab (or GitHub), you will
        first need to export all the pages you wish to convert from Mediawiki into an XML file:

            1. MediaWiki -> Special Pages -> 'All Pages'
            2. With help from the filter tool at the top of 'All Pages', copy the page names
               to convert into a text file (one file name per line).
            3. MediaWiki -> Special Pages -> 'Export'
            4. Paste the list of pages into the Export field.
               Note: This convert script will only do latest version, not revisions.
            5. Check: 'Include only the current revision, not the full history'
            6. Uncheck: Include Templates
            7. Check: Save as file
            8. Click on the 'Export' button.

        In theory you can convert to any of the formats listed at:
            https://pandoc.org/MANUAL.html#description

        HELPMESSAGE;

        $this->message($helpMessage);
    }
}
