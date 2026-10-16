<?php

namespace Tests\Feature;

use App\Models\ContentAsset;
use App\Services\ProtectedS3Media;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProtectedS3MediaTest extends TestCase
{
    public function test_s3_range_is_requested_from_private_bucket_and_streamed_as_partial_content(): void
    {
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $client = Mockery::mock(S3Client::class);
        $disk->shouldReceive('size')->with('video.mp4')->andReturn(10);
        $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'private-test']);
        $disk->shouldReceive('path')->with('video.mp4')->andReturn('prefix/video.mp4');
        $disk->shouldReceive('getClient')->andReturn($client);
        $client->shouldReceive('getObject')->once()->with(Mockery::on(fn ($options) => $options['Range'] === 'bytes=2-5'
            && $options['Bucket'] === 'private-test' && $options['Key'] === 'prefix/video.mp4'))
            ->andReturn(new Result(['Body' => Utils::streamFor('2345')]));
        $request = Request::create('/play', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=2-5']);
        $response = app(ProtectedS3Media::class)->response($request, new ContentAsset(['storage_key' => 'video.mp4']), $disk, []);
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
        $this->assertSame('4', $response->headers->get('Content-Length'));
        ob_start();
        $response->sendContent();
        $output = ob_get_clean();
        $this->assertSame('2345', $output);
    }

    public function test_out_of_bounds_or_multiple_ranges_are_rejected_before_s3_download(): void
    {
        foreach (['bytes=12-20', 'bytes=0-1,4-5', 'bytes=-0', 'bytes=-', 'garbage'] as $range) {
            $disk = Mockery::mock(AwsS3V3Adapter::class);
            $disk->shouldReceive('size')->andReturn(10);
            $disk->shouldNotReceive('getClient');
            try {
                app(ProtectedS3Media::class)->response(Request::create('/play', 'GET', [], [], [], ['HTTP_RANGE' => $range]),
                    new ContentAsset(['storage_key' => 'video.mp4']), $disk, []);
                $this->fail('Invalid range accepted: '.$range);
            } catch (HttpException $e) {
                $this->assertSame(416, $e->getStatusCode());
            }
        }
    }
}
