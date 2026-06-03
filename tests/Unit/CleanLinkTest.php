<?php
// SPDX-FileCopyrightText: 2026 Out of Control, Inc.
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Tests\Unit;

use App\CleanLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanLink::class)]
final class CleanLinkTest extends TestCase
{
    /** File meta used as the "current page" context for relative-link resolution. */
    private const array META = ['url' => 'Path/To/Files'];

    private function makeLink(bool $flatten = false): CleanLink
    {
        return new CleanLink($flatten, self::META);
    }

    /**
     * cleanLink() receives a preg match array; index 0 is the full match, index 1 the inner text.
     *
     * @return array{0: string, 1: string}
     */
    private function match(string $inner): array
    {
        return ["[[$inner]]", $inner];
    }

    #[Test]
    #[DataProvider('normalizePathProvider')]
    public function it_normalizes_paths(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->makeLink()->normalizePath($input));
    }

    /** @return array<string, array{string, string}> */
    public static function normalizePathProvider(): array
    {
        return [
            'resolves dot and dotdot segments' => [
                '/test/../one/four/six/../.././two/./three',
                '/one/two/three',
            ],
            'collapses repeated slashes' => ['a//b///c', 'a/b/c'],
            'leading dotdot is preserved' => ['../one/two', '../one/two'],
            'plain path is unchanged' => ['one/two/three', 'one/two/three'],
            'backslashes become forward slashes' => ['one\\two\\three', 'one/two/three'],
        ];
    }

    #[Test]
    #[DataProvider('cleanLinkProvider')]
    public function it_cleans_wiki_links(bool $flatten, string $inner, string $expected): void
    {
        $cleanLink = $this->makeLink($flatten);

        $this->assertSame($expected, $cleanLink->cleanLink($this->match($inner)));
    }

    /** @return array<string, array{bool, string, string}> */
    public static function cleanLinkProvider(): array
    {
        return [
            'nested: relative ../ resolves against current page' => [
                false,
                '../minutes|Link to Minutes',
                '[[Path/To/minutes|Link to Minutes]]',
            ],
            'nested: absolute path kept as-is' => [
                false,
                'Directory/structure/2017/minutes|Link to Minutes',
                '[[Directory/structure/2017/minutes|Link to Minutes]]',
            ],
            'flat: relative ../ flattened with underscores' => [
                true,
                '../minutes|Link to Minutes',
                '[[Path_To_minutes|Link to Minutes]]',
            ],
            'flat: nested path flattened with underscores' => [
                true,
                'Directory/structure/2017/minutes|Link to Minutes',
                '[[Directory_structure_2017_minutes|Link to Minutes]]',
            ],
            'spaces in link become underscores' => [
                false,
                'Some Page|Some Page',
                '[[Some_Page|Some Page]]',
            ],
            'link without explicit text reuses the target' => [
                false,
                'Target',
                '[[Target|Target]]',
            ],
        ];
    }

    #[Test]
    #[DataProvider('externalLinkProvider')]
    public function it_passes_through_malformed_external_links_as_plain_links(string $inner): void
    {
        // A [[..]] wrapping an http(s) URL is a user error; it is returned as a single-bracket link.
        $expected = '[' . $inner . ']';

        $this->assertSame($expected, $this->makeLink()->cleanLink($this->match($inner)));
        $this->assertSame($expected, $this->makeLink(true)->cleanLink($this->match($inner)));
    }

    /** @return array<string, array{string}> */
    public static function externalLinkProvider(): array
    {
        return [
            'http link' => ['http://domain.com/?a=1&b=&c=3 a text link'],
            'https link' => ['https://domain.com/this/is/external/ Improperly formatted link'],
        ];
    }
}
