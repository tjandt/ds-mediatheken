<?php

namespace Tests;

use TheiNaD\DSMediatheken\Mediatheken\ARD;
use TheiNaD\DSMediatheken\Utils\Curl;
use TheiNaD\DSMediatheken\Utils\Logger;
use TheiNaD\DSMediatheken\Utils\Result;
use TheiNaD\DSMediatheken\Utils\Tools;

/**
 * Unit Test for ARD
 *
 * The fixtures follow the layout of the page gateway with `mcV6=true`. They are modelled on the response parsed by
 * yt-dlp, because the API could not be requested while writing them. Please replace them with a real response.
 *
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2017-2022 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
final class ARDTest extends TestCase
{
    private const DOCUMENT_ID =
        'Y3JpZDovL2Rhc2Vyc3RlLmRlL2ZpbG1taXR0d29jaCBpbSBlcnN0ZW4vMjE4MzkzZTMtYjIyMy00Njg2LWJmNTMtM2M2NTg5MzIxYzMw';
    private const MEDIA_FILE_URL =
        'https://pdvideosdaserste-a.akamaihd.net/int/2021/10/04/218393e3-b223-4686-bf53-3c6589321c30/' .
        '1920-1_978535.mp4';

    /**
     * @dataProvider validUrlProvider
     *
     * @param string $downloadUrl
     *
     * @return void
     */
    public function testDownloadInfoCanBeRetrievedFromValidUrl($downloadUrl): void
    {
        $result = $this->getDownloadInfo($downloadUrl, 'ard/apiResponse.json');

        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(self::MEDIA_FILE_URL, $result->getUri());
        $this->assertEquals(1080, $result->getQualityRating());
        $this->assertEquals('FilmMittwoch im Ersten', $result->getTitle());
        $this->assertEquals('Geliefert', $result->getEpisodeTitle());
    }

    /**
     * @return array
     */
    public function validUrlProvider()
    {
        return [
            'video url' => [
                'https://www.ardmediathek.de/video/filmmittwoch-im-ersten/geliefert-oder-drama/das-erste/' .
                    self::DOCUMENT_ID . '/',
            ],
            'without trailing slash' => ['https://www.ardmediathek.de/video/x/y/das-erste/' . self::DOCUMENT_ID],
            'with query' => ['https://www.ardmediathek.de/ard/player/' . self::DOCUMENT_ID . '?devicetype=pc#t=3'],
        ];
    }

    public function testNothingIsReturnedIfOnlyHlsIsAvailable(): void
    {
        $this->assertNull($this->getDownloadInfo(
            'https://www.ardmediathek.de/video/x/y/das-erste/' . self::DOCUMENT_ID,
            'ard/apiResponseHlsOnly.json'
        ));
    }

    public function testNothingIsReturnedIfVideoIsBlockedByAgeRating(): void
    {
        $this->assertNull($this->getDownloadInfo(
            'https://www.ardmediathek.de/video/x/y/das-erste/' . self::DOCUMENT_ID,
            'ard/apiResponseBlockedByFsk.json'
        ));
    }

    public function testNothingIsReturnedIfApiRequestFails(): void
    {
        $logger = $this->createMock(Logger::class);
        $curl = $this->createMock(Curl::class);
        $curl->method('request')->willThrowException(new \RuntimeException('failed'));

        $ard = new ARD($logger, new Tools($logger, $curl));

        $this->assertNull($ard->getDownloadInfo('https://www.ardmediathek.de/video/x/y/das-erste/abc'));
    }

    /**
     * @param string $downloadUrl
     * @param string $fixture
     *
     * @return Result|null
     */
    private function getDownloadInfo($downloadUrl, $fixture)
    {
        $logger = $this->createMock(Logger::class);
        $curl = $this->createMock(Curl::class);
        $tools = new Tools($logger, $curl);

        $curl->expects($this->once())
            ->method('request')
            ->with($this->equalTo(ARD::$API_BASE_URL . self::DOCUMENT_ID . '?' . ARD::$API_QUERY))
            ->willReturn($this->getFixture($fixture));

        return (new ARD($logger, $tools))->getDownloadInfo($downloadUrl);
    }
}
