<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Agent-facing text (tool descriptions, input schema descriptions, result text) must
 * name tools the way tools/list publishes them: `catalog_product_get`, not `catalog.product.get`.
 */
class WireNameTextTest extends TestCase
{
    private const SCANNED_DIRS = ['Tool', 'Model'];

    /**
     * Dotted names of tools in other MCP modules, matched by their module prefix.
     */
    private const CROSS_MODULE_PATTERN = '/(?<![\w.\\\\])(?:catalog|cms|customer|tax|directory|system|inventory|sales'
        . '|marketing|reports?|order|hyva_[a-z]+)\.[a-z_]+\.[a-z_]+(?:\.[a-z_]+)*(?![\w])/';

    public function testNoStringLiteralNamesAToolWithDots(): void
    {
        $moduleDir = dirname(__DIR__, 3);
        $toolNames = $this->registeredToolNames($moduleDir . '/etc/di.xml');
        self::assertContains('catalog.product.get', $toolNames);

        $found = [];
        foreach (self::SCANNED_DIRS as $dir) {
            foreach ($this->phpFiles($moduleDir . '/' . $dir) as $file) {
                $code = file_get_contents($file);
                self::assertIsString($code);
                foreach ($this->agentFacingStrings($code) as [$line, $text]) {
                    $where = sprintf('%s:%d', substr($file, strlen($moduleDir) + 1), $line);
                    foreach ($toolNames as $name) {
                        if (preg_match('/(?<![\w.])' . preg_quote($name, '/') . '(?![\w])/', $text) === 1) {
                            $found[] = $where . ' ' . $name;
                        }
                    }
                    if (preg_match_all(self::CROSS_MODULE_PATTERN, $text, $matches) > 0) {
                        foreach ($matches[0] as $name) {
                            $found[] = $where . ' ' . $name;
                        }
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($found)), 'Use the underscore wire name in agent-facing text.');
    }

    public function testToolNameConstantIsSkippedWithOrWithoutAType(): void
    {
        $code = "<?php\nclass A\n{\n"
            . "    public const TOOL_NAME = 'cms.page.get';\n"
            . "    public const string TOOL_NAME = 'cms.page.get';\n"
            . "    private const WARNING = 'Use cms.page.get.';\n"
            . "    public function d(): string\n    {\n        return 'See cms.page.get.';\n    }\n}\n";

        self::assertSame(
            [[6, "'Use cms.page.get.'"], [9, "'See cms.page.get.'"]],
            $this->agentFacingStrings($code)
        );
    }

    /**
     * @param string $diXml
     * @return array<int, string>
     */
    private function registeredToolNames(string $diXml): array
    {
        $xml = simplexml_load_file($diXml);
        self::assertNotFalse($xml);
        $items = $xml->xpath(
            '//type[@name="Magebit\Mcp\Model\Tool\ToolRegistry"]/arguments/argument[@name="tools"]/item/@name'
        );
        self::assertIsArray($items);

        $names = [];
        foreach ($items as $item) {
            $names[] = (string) $item;
        }
        return $names;
    }

    /**
     * @param string $dir
     * @return array<int, string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * String literals and heredoc bodies, skipping the value of `const TOOL_NAME`, typed or not
     * (the canonical dotted name stays the registry key).
     *
     * @param string $code
     * @return array<int, array{int, string}>
     */
    private function agentFacingStrings(string $code): array
    {
        $strings = [];
        $inConstName = false;
        $skipValue = false;
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                if ($token === '=') {
                    $inConstName = false;
                } elseif ($token === ';') {
                    $inConstName = false;
                    $skipValue = false;
                }
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_CONST) {
                $inConstName = true;
                continue;
            }
            if ($inConstName && $id === T_STRING && $text === 'TOOL_NAME') {
                $skipValue = true;
                continue;
            }
            if (!$skipValue && ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)) {
                $strings[] = [$line, $text];
            }
        }
        return $strings;
    }
}
