<?php

namespace SatApi\Tests\Utils;

use PHPUnit\Framework\TestCase;
use SatApi\Utils\FileHandler;

class FileHandlerTest extends TestCase
{
    private $fileHandler;

    protected function setUp(): void
    {
        $this->fileHandler = new FileHandler();
    }

    public function testCreateTempFile()
    {
        $content = 'test content';
        $tempFile = $this->fileHandler->createTempFile($content);

        $this->assertFileExists($tempFile);
        $this->assertEquals($content, file_get_contents($tempFile));

        $this->fileHandler->cleanupTempFiles();
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testValidateFileType()
    {
        $this->assertTrue($this->fileHandler->validateFileType('test.cer'));
        $this->assertTrue($this->fileHandler->validateFileType('test.key'));
        $this->assertFalse($this->fileHandler->validateFileType('test.txt'));
    }

    public function testValidateFileSize()
    {
        $this->assertTrue($this->fileHandler->validateFileSize(1024));
        $this->assertFalse($this->fileHandler->validateFileSize(6 * 1024 * 1024)); // 6MB
    }

    public function testCleanupTempFiles()
    {
        $tempFile1 = $this->fileHandler->createTempFile('test1');
        $tempFile2 = $this->fileHandler->createTempFile('test2');

        $this->assertFileExists($tempFile1);
        $this->assertFileExists($tempFile2);

        $this->fileHandler->cleanupTempFiles();

        $this->assertFileDoesNotExist($tempFile1);
        $this->assertFileDoesNotExist($tempFile2);
    }
}
