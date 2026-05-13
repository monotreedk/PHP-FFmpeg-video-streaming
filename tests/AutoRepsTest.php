<?php

/**
 * This file is part of the PHP-FFmpeg-video-streaming package.
 *
 * (c) Amin Yazdanpanah <contact@aminyazdanpanah.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\FFMpegStreaming;

use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\FFProbe\DataMapping\Stream as ProbeStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use FFMpeg\Media\Video as FFMpegVideo;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Streaming\AutoReps;
use Streaming\Format\X264;
use Streaming\Media;
use Streaming\Representation;

class AutoRepsTest extends BaseTestCase
{
    /**
     * Regression: a portrait video with a non-standard aspect ratio (e.g. an iPhone
     * screen recording at 1206x2622) must not have its representations snapped to
     * "the nearest standard aspect ratio". Doing so produces vertically stretched
     * HLS/DASH output.
     */
    public function testPortraitNonStandardAspectIsPreserved(): void
    {
        $sourceWidth = 1206;
        $sourceHeight = 2622;
        $sourceRatio = $sourceWidth / $sourceHeight;

        $autoReps = $this->makeAutoReps($sourceWidth, $sourceHeight, [480, 720, 1080]);

        $reps = iterator_to_array($autoReps);

        # Sanity: 3 calculated reps + 1 original rep
        $this->assertCount(4, $reps);

        foreach ($reps as $rep) {
            /** @var Representation $rep */
            $repRatio = $rep->getWidth() / $rep->getHeight();

            # Modulus-2 rounding can drift the ratio by at most ~2/height. We allow 1%.
            $drift = abs($repRatio - $sourceRatio) / $sourceRatio;

            $this->assertLessThan(
                0.01,
                $drift,
                sprintf(
                    'Representation %dx%d (ratio %.4f) drifts %.2f%% from source ratio %.4f',
                    $rep->getWidth(), $rep->getHeight(), $repRatio, $drift * 100, $sourceRatio
                )
            );
        }
    }

    /**
     * Landscape 16:9 source must still snap to the clean 16:9 ratio (i.e. exact dims),
     * so we don't regress the well-aligned common case.
     */
    public function testLandscape16x9StaysExact(): void
    {
        $autoReps = $this->makeAutoReps(1920, 1080, [480, 720]);

        $reps = iterator_to_array($autoReps);

        $dims = array_map(fn(Representation $r) => $r->getWidth() . 'x' . $r->getHeight(), $reps);
        sort($dims);

        $this->assertSame(['1280x720', '1920x1080', '854x480'], $dims);
    }

    /**
     * @param int[] $sides
     */
    private function makeAutoReps(int $width, int $height, array $sides): AutoReps
    {
        $videoStream = new ProbeStream([
            'codec_type' => 'video',
            'width'      => $width,
            'height'     => $height,
            'bit_rate'   => 1_500_000,
        ]);
        $streams = new StreamCollection([$videoStream]);
        $format = new Format(['bit_rate' => 1_500_000]);

        $ffmpegVideo = $this->getMockBuilder(FFMpegVideo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStreams', 'getFormat'])
            ->getMock();
        $ffmpegVideo->method('getStreams')->willReturn($streams);
        $ffmpegVideo->method('getFormat')->willReturn($format);

        $media = new Media($ffmpegVideo, false);

        return new AutoReps($media, new X264(), $sides);
    }
}
