<?php

namespace Tests;

use TheiNaD\DSMediatheken\Mediatheken\ZDF;
use TheiNaD\DSMediatheken\Utils\Curl;
use TheiNaD\DSMediatheken\Utils\Logger;
use TheiNaD\DSMediatheken\Utils\Result;
use TheiNaD\DSMediatheken\Utils\Tools;

/**
 * Unit Test for ZDF
 *
 * The playlists (PTMD) are real responses, the token and the GraphQL responses follow the layout parsed by yt-dlp,
 * because the APIs could not be requested while writing them. Please replace them with real responses.
 *
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2017-2022 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
final class ZDFTest extends TestCase
{
    /**
     * @dataProvider dataProvider
     *
     * @param string $downloadUrl
     * @param string $canonical
     * @param string $graphqlFixture
     * @param string $ptmdUrl
     * @param string $ptmdFixture
     * @param string $mediaFileUrl
     * @param string $title
     * @param string $episodeTitle
     *
     * @return void
     */
    public function testDownloadFromValidUrl(
        $downloadUrl,
        $canonical,
        $graphqlFixture,
        $ptmdUrl,
        $ptmdFixture,
        $mediaFileUrl,
        $title,
        $episodeTitle
    ): void {
        $curl = $this->createMock(Curl::class);

        $curl->expects($this->exactly(3))
            ->method('request')
            ->withConsecutive(
                [$this->equalTo(ZDF::TOKEN_URL)],
                [
                    $this->equalTo(ZDF::GRAPHQL_URL),
                    $this->callback(function ($options) use ($canonical) {
                        $body = json_decode($options[CURLOPT_POSTFIELDS], true);

                        return $options[CURLOPT_POST] === true &&
                            $body['variables'] === ['canonical' => $canonical] &&
                            in_array('Api-Auth: Bearer test-token', $options[CURLOPT_HTTPHEADER], true) &&
                            in_array('Apollo-Require-Preflight: true', $options[CURLOPT_HTTPHEADER], true);
                    }),
                ],
                [
                    $this->equalTo($ptmdUrl),
                    $this->equalTo([CURLOPT_HTTPHEADER => ['Api-Auth: Bearer test-token']]),
                ]
            )
            ->willReturnOnConsecutiveCalls(
                $this->getFixture('zdf/token.json'),
                $this->getFixture($graphqlFixture),
                $this->getFixture($ptmdFixture)
            );

        $result = $this->createZdf($curl)->getDownloadInfo($downloadUrl);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals($mediaFileUrl, $result->getUri());
        $this->assertEquals($title, $result->getTitle());
        $this->assertEquals($episodeTitle, $result->getEpisodeTitle());
    }

    /**
     * @return array
     */
    public function dataProvider()
    {
        return [
            'video url' => [
                'https://www.zdf.de/video/kinder/bibi-blocksberg-100/besenrennen-102',
                'besenrennen-102',
                'zdf/graphql.json',
                'https://api.zdf.de/tmd/2/android_native_6/vod/ptmd/tivi/180217_besenrennen_folge51_bib',
                'zdf/formitaeten.json',
                'https://nrodlzdf-a.akamaihd.net/de/tivi/18/02/180217_besenrennen_folge51_bib/3/' .
                    '180217_besenrennen_folge51_bib_1496k_p13v13.mp4',
                'Bibi Blocksberg',
                'Das große Besenrennen',
            ],
            'play url with query' => [
                'https://www.zdf.de/play/serien/bad-banks-102/schoene-neue-welt-138?staffel=1',
                'schoene-neue-welt-138',
                'zdf/ad/graphql.json',
                'https://api.zdf.de/tmd/2/android_native_6/vod/ptmd/mediathek/200208_2145_sendung_bad',
                'zdf/ad/formitaeten.json',
                // not the audio description
                'https://nrodlzdf-a.akamaihd.net/dach/zdf/20/02/200208_2145_sendung_bad/3/' .
                    '200208_2145_sendung_bad_a1a2_1496k_p13v14.mp4',
                'Bad Banks',
                'Schöne neue Welt',
            ],
            'legacy html url' => [
                'https://www.zdf.de/serien/bad-banks/schoene-neue-welt-138.html',
                'schoene-neue-welt-138',
                'zdf/ad/graphql.json',
                'https://api.zdf.de/tmd/2/android_native_6/vod/ptmd/mediathek/200208_2145_sendung_bad',
                'zdf/ad/formitaeten.json',
                'https://nrodlzdf-a.akamaihd.net/dach/zdf/20/02/200208_2145_sendung_bad/3/' .
                    '200208_2145_sendung_bad_a1a2_1496k_p13v14.mp4',
                'Bad Banks',
                'Schöne neue Welt',
            ],
        ];
    }

    public function testVideoWithoutSeparateSeriesTitleHasOnlyOneTitle(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('request')->willReturnOnConsecutiveCalls(
            $this->getFixture('zdf/token.json'),
            $this->getFixture('zdf/graphqlSignLanguageFirst.json'),
            $this->getFixture('zdf/formitaeten.json')
        );

        $result = $this->createZdf($curl)->getDownloadInfo('https://www.zdf.de/video/filme/movie-100');

        $this->assertEquals('', $result->getTitle());
        $this->assertEquals('Ein Film', $result->getEpisodeTitle());
    }

    public function testVariantWithSignLanguageIsOnlyRequestedAsFallback(): void
    {
        $curl = $this->createMock(Curl::class);

        $curl->expects($this->exactly(3))
            ->method('request')
            ->withConsecutive(
                [$this->equalTo(ZDF::TOKEN_URL)],
                [$this->equalTo(ZDF::GRAPHQL_URL)],
                [$this->equalTo('https://api.zdf.de/tmd/2/android_native_6/vod/ptmd/mediathek/regular_variant')]
            )
            ->willReturnOnConsecutiveCalls(
                $this->getFixture('zdf/token.json'),
                $this->getFixture('zdf/graphqlSignLanguageFirst.json'),
                $this->getFixture('zdf/formitaeten.json')
            );

        $this->assertInstanceOf(
            Result::class,
            $this->createZdf($curl)->getDownloadInfo('https://www.zdf.de/video/filme/movie-100')
        );
    }

    public function testNothingIsReturnedIfVideoDoesNotExist(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('request')->willReturnOnConsecutiveCalls(
            $this->getFixture('zdf/token.json'),
            $this->getFixture('zdf/graphqlNotFound.json')
        );

        $this->assertNull($this->createZdf($curl)->getDownloadInfo('https://www.zdf.de/video/filme/missing-100'));
    }

    public function testNothingIsReturnedIfTokenCanNotBeRetrieved(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('request')->willReturn('{"message":"error"}');

        $this->assertNull($this->createZdf($curl)->getDownloadInfo('https://www.zdf.de/video/filme/movie-100'));
    }

    /**
     * @param Curl $curl
     *
     * @return ZDF
     */
    private function createZdf(Curl $curl): ZDF
    {
        $logger = $this->createMock(Logger::class);

        return new ZDF($logger, new Tools($logger, $curl));
    }
}
