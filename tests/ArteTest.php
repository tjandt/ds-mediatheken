<?php

namespace Tests;

use TheiNaD\DSMediatheken\Mediatheken\Arte;
use TheiNaD\DSMediatheken\Utils\Curl;
use TheiNaD\DSMediatheken\Utils\Logger;
use TheiNaD\DSMediatheken\Utils\Result;
use TheiNaD\DSMediatheken\Utils\Tools;

/**
 * Unit Test for Arte
 *
 * The fixtures follow the layout of the player config v2. They are modelled on the response parsed by yt-dlp,
 * because the API could not be requested while writing them. Please replace them with a real response.
 *
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2018-2022 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
final class ArteTest extends TestCase
{
    private const MEDIA_FILE_URL_DE =
        'https://arteptweb-a.akamaihd.net/am/ptweb/088000/088300/' .
        '088376-000-A_SQ_0_VA_04785375_MP4-2200_AMM-PTWEB_5.mp4';
    private const MEDIA_FILE_URL_FR =
        'https://arteptweb-a.akamaihd.net/am/ptweb/088000/088300/' .
        '088376-000-A_SQ_0_VOF_04785376_MP4-2200_AMM-PTWEB_6.mp4';

    /**
     * @dataProvider validUrlProvider
     *
     * @param string $downloadUrl
     * @param string $apiLanguage
     * @param string $mediaFileUrl
     *
     * @return void
     */
    public function testDownloadInfoCanBeRetrievedFromValidUrl($downloadUrl, $apiLanguage, $mediaFileUrl): void
    {
        $result = $this->getDownloadInfo($downloadUrl, $apiLanguage, 'arte/apiResponse.json');

        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals($mediaFileUrl, $result->getUri());
        $this->assertEquals(2200, $result->getBitrateRating());
        $this->assertEquals('Lou Andreas-Salomé', $result->getTitle());
        $this->assertEquals('', $result->getEpisodeTitle());
    }

    /**
     * @return array
     */
    public function validUrlProvider()
    {
        return [
            'german' => [
                'https://www.arte.tv/de/videos/088376-000-A/lou-andreas-salome/',
                'de',
                self::MEDIA_FILE_URL_DE,
            ],
            'german without slug' => [
                'https://www.arte.tv/de/videos/088376-000-A',
                'de',
                self::MEDIA_FILE_URL_DE,
            ],
            'french' => [
                'https://www.arte.tv/fr/videos/088376-000-A/lou-andreas-salome/',
                'fr',
                self::MEDIA_FILE_URL_FR,
            ],
            'legacy guide url' => [
                'https://www.arte.tv/guide/de/088376-000-A/lou-andreas-salome',
                'de',
                self::MEDIA_FILE_URL_DE,
            ],
        ];
    }

    public function testHighestBitrateOfBestMatchingVersionWins(): void
    {
        $config = json_decode($this->getFixture('arte/apiResponse.json'), true);
        $config['data']['attributes']['streams'] = array_values(array_filter(
            $config['data']['attributes']['streams'],
            function ($stream) {
                return $stream['versions'][0]['eStat']['ml5'] !== 'VA';
            }
        ));

        $result = $this->getDownloadInfo(
            'https://www.arte.tv/de/videos/088376-000-A/lou-andreas-salome/',
            'de',
            json_encode($config)
        );

        $this->assertEquals(
            'https://arteptweb-a.akamaihd.net/am/ptweb/088000/088300/' .
                '088376-000-A_SQ_0_VOA-STA_04785373_MP4-2200_AMM-PTWEB_3.mp4',
            $result->getUri()
        );
    }

    public function testNothingIsReturnedIfOnlyHlsIsAvailable(): void
    {
        $messages = [];
        $logger = $this->createMock(Logger::class);
        $logger->method('log')->willReturnCallback(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $this->assertNull($this->getDownloadInfo(
            'https://www.arte.tv/de/videos/088376-000-A/lou-andreas-salome/',
            'de',
            'arte/apiResponseHlsOnly.json',
            $logger
        ));
        $this->assertContains(
            'the video is only available as HLS stream, which can not be downloaded by Download Station',
            $messages
        );
    }

    public function testNothingIsReturnedIfBroadcastRightsExpired(): void
    {
        $this->assertNull($this->getDownloadInfo(
            'https://www.arte.tv/de/videos/088376-000-A/lou-andreas-salome/',
            'de',
            'arte/apiResponseNoRights.json'
        ));
    }

    public function testNothingIsReturnedIfUrlContainsNoVideoId(): void
    {
        $logger = $this->createMock(Logger::class);
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->never())->method('request');

        $arte = new Arte($logger, new Tools($logger, $curl));

        $this->assertNull($arte->getDownloadInfo('https://www.arte.tv/de/videos/RC-021383/some-collection/'));
    }

    /**
     * @param string $downloadUrl
     * @param string $apiLanguage
     * @param string $fixture name of a fixture or the json itself
     * @param Logger $logger
     *
     * @return Result|null
     */
    private function getDownloadInfo($downloadUrl, $apiLanguage, $fixture, Logger $logger = null)
    {
        $logger = $logger ?? $this->createMock(Logger::class);
        $curl = $this->createMock(Curl::class);
        $tools = new Tools($logger, $curl);

        $curl->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo(sprintf(Arte::API_URL_PATTERN, $apiLanguage, '088376-000-A')),
                $this->equalTo([CURLOPT_HTTPHEADER => ['x-validated-age: 18']])
            )
            ->willReturn(substr($fixture, 0, 1) === '{' ? $fixture : $this->getFixture($fixture));

        return (new Arte($logger, $tools))->getDownloadInfo($downloadUrl);
    }
}
