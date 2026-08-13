<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model\Media;

use Magebit\McpCatalogTools\Model\Media\ImagePayloadDecoder;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\ImageContentValidator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ImagePayloadDecoderTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42m'
        . 'P8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** 8x8 WebP — a real image, but one Magento's gallery refuses. */
    private const WEBP_BASE64 = 'UklGRjYAAABXRUJQVlA4ICoAAACQAQCdASoIAAgAAUAmJaACdLoAA5gA'
        . 'm/xviC9q8f/fAn/vAn/vAn+2wAA=';

    /** 1x1 GIF. */
    private const GIF_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function testDetectsMimeTypeFromBytes(): void
    {
        $captured = $this->capture(self::PNG_BASE64, 'photo.png');

        $this->assertSame('image/png', $captured['type']);
        $this->assertSame('photo.png', $captured['name']);
    }

    public function testAcceptsDataUriWrapper(): void
    {
        $captured = $this->capture('data:image/gif;name=x.gif;base64,' . self::GIF_BASE64, 'anim.gif');

        $this->assertSame('image/gif', $captured['type']);
    }

    /**
     * Magento's own gallery validator rejects WebP, so accepting it here would
     * only defer the failure to a less clear error.
     */
    public function testRejectsWebpBecauseMagentoWould(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not accepted by this store');

        $this->capture(self::WEBP_BASE64, 'shot.webp');
    }

    /**
     * The declared extension must not decide the stored one — a PNG uploaded as
     * "evil.php" has to land as a .png.
     */
    public function testExtensionComesFromSniffedTypeNotFilename(): void
    {
        $this->assertSame('evil.png', $this->capture(self::PNG_BASE64, 'evil.php')['name']);
    }

    public function testStripsPathTraversalFromFilename(): void
    {
        $this->assertSame('passwd.png', $this->capture(self::PNG_BASE64, '../../../etc/passwd')['name']);
    }

    public function testFallsBackToDefaultFilename(): void
    {
        $this->assertSame('image.png', $this->capture(self::PNG_BASE64, null)['name']);
    }

    public function testReEncodesPayloadWithoutTheDataUriPrefix(): void
    {
        $captured = $this->capture('data:image/png;base64,' . self::PNG_BASE64, 'photo.png');

        $this->assertSame(self::PNG_BASE64, $captured['data']);
    }

    public function testRejectsNonBase64(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not valid base64');

        $this->capture('!!!not base64!!!', 'x.png');
    }

    public function testRejectsNonImagePayload(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not a readable image');

        $this->capture(base64_encode('<?php echo "pwned";'), 'x.png');
    }

    public function testRejectsEmptyPayload(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('empty');

        $this->capture('   ', 'x.png');
    }

    public function testRejectsOversizedImage(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('the limit is');

        $this->capture(base64_encode(str_repeat('A', ImagePayloadDecoder::MAX_IMAGE_BYTES + 1)), 'big.png');
    }

    /**
     * Runs the factory against a recording ImageContentInterface and returns
     * what it set. The recorder also answers the getters, because the real
     * validator reads them back.
     *
     * @param string $payload
     * @param string|null $filename
     * @return array<string, string>
     * @throws LocalizedException
     */
    private function capture(string $payload, ?string $filename): array
    {
        $captured = [];

        $content = $this->createMock(ImageContentInterface::class);
        $content->method('setBase64EncodedData')->willReturnCallback(
            function ($value) use (&$captured): void {
                $captured['data'] = (string) $value;
            }
        );
        $content->method('setType')->willReturnCallback(
            function ($value) use (&$captured): void {
                $captured['type'] = (string) $value;
            }
        );
        $content->method('setName')->willReturnCallback(
            function ($value) use (&$captured): void {
                $captured['name'] = (string) $value;
            }
        );
        // Closures, not arrow functions — `fn` captures by value and would
        // freeze an empty array here.
        $content->method('getBase64EncodedData')->willReturnCallback(
            function () use (&$captured) {
                return $captured['data'] ?? null;
            }
        );
        $content->method('getType')->willReturnCallback(
            function () use (&$captured) {
                return $captured['type'] ?? null;
            }
        );
        $content->method('getName')->willReturnCallback(
            function () use (&$captured) {
                return $captured['name'] ?? null;
            }
        );

        $contentFactory = $this->createMock(ImageContentInterfaceFactory::class);
        $contentFactory->method('create')->willReturn($content);

        (new ImagePayloadDecoder($contentFactory, new ImageContentValidator()))->create($payload, $filename);

        /** @var array<string, string> $captured */
        return $captured;
    }
}
