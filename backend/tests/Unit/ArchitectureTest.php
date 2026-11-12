<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces ADR-001: domain modules stay free of HTTP concerns and reach other
 * modules only through that module's published Contracts namespace.
 */
class ArchitectureTest extends TestCase
{
    private const MODULES = ['Identity', 'Geo', 'Merchants', 'Catalog', 'Orders', 'Dispatch', 'Payments', 'Ledger', 'Support'];

    public function test_expected_domain_modules_exist(): void
    {
        foreach (self::MODULES as $module) {
            $this->assertDirectoryExists($this->domainPath().'/'.$module);
        }
    }

    public function test_domain_code_does_not_import_http_layer(): void
    {
        foreach ($this->domainFiles() as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+(App\\\\Http|Illuminate\\\\Http|Illuminate\\\\Routing)\\\\/m',
                (string) file_get_contents($file),
                "{$file} imports the HTTP layer.",
            );
        }

        $this->addToAssertionCount(1);
    }

    public function test_modules_reference_each_other_only_through_contracts(): void
    {
        foreach ($this->domainFiles() as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($this->domainPath()) + 1));
            $own = explode('/', $relative)[0];
            preg_match_all('/^use\s+App\\\\Domain\\\\(\w+)\\\\(\w+)/m', (string) file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if ($match[1] !== $own) {
                    $this->assertSame(
                        'Contracts',
                        $match[2],
                        "{$relative} imports {$match[1]}\\{$match[2]}; only {$match[1]}\\Contracts is allowed.",
                    );
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    private function domainPath(): string
    {
        return dirname(__DIR__, 2).'/app/Domain';
    }

    /** @return list<string> */
    private function domainFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->domainPath()));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
