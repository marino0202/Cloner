<?php

namespace App\Service;

use DateTime;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ClonerService
{
    private Client $client;

    private HttpClientInterface $curl;

    private Crawler $crawler;

    private Filesystem $fs;

    private SymfonyStyle $io;

    private string $url;

    private array $data, $link;

    public function __construct(SymfonyStyle $symfonyStyle, string $url)
    {
        $this->data = [];
        $this->link = [];
        $this->url = $url;
        $this->io = $symfonyStyle;
        echo preg_replace("/[^:]*:\/\//", '', $this->url);
        echo PHP_EOL;

        $this->init();
    }

    protected function init(): void
    {
        echo "Initializing" . PHP_EOL;
        $this->client = Client::createChromeClient();
        $this->curl = new CurlHttpClient();
        $this->fs = new Filesystem();

        $this->crawler = $this->request($this->url, true);
        $this->readAttr();
        $this->readLink();
        $this->close();
    }

    protected function request(string $url, bool $wait = false, int $for = 10): Crawler
    {
        echo "Requesting " . $url . PHP_EOL;
        $this->client->request('GET', $url);
        if ($wait) {
            $later = time() + $for;
            while ($later > time()) {
                $this->client->wait();
            }
        }
        $crawler = $this->client->getCrawler();
        return $crawler;
    }

    protected function httpRequest(string $url): ResponseInterface
    {
        // echo "Requesting ".$url.PHP_EOL;
        $crawler = $this->curl->request('GET', $url);
        return $crawler;
    }

    protected function readAttr(): void
    {
        echo "Attempting to read attributes" . PHP_EOL;
        $data = [['src', 'script'], ['src', 'img'], ['srcset', 'img'], ['data-src', 'img'], ['data-srcset', 'img'], ['href', 'link'], ['content', 'meta']];
        $v = $this->crawler->html();

        foreach ($data as $key) {
            $this->data[$key[1]] = array_key_exists($key[1], $this->data) ? $this->data[$key[1]] : [];
            array_push($this->data[$key[1]], $this->nodeAttr($key[1], $key[0], $v));
        }
        // split all \s separated elements into diff elements ['script' => [0 => []]]
        foreach ($this->data as $key => $value) {
            foreach ($value as $num => $arr) {
                foreach ($arr as $elem) {
                    $elem = trim($elem);
                    if (preg_match("/.\s+./", $elem)) {
                        $this->data[$key][$num] = array_merge($this->data[$key][$num], explode(' ', $elem));
                        array_splice($this->data[$key][$num], array_search($elem, $this->data[$key][$num]), 1);
                        continue;
                    }

                }
            }
        }
        // remove all element that is not a url
        foreach ($this->data as $key => $value) {
            foreach ($value as $num => $arr) {
                foreach ($arr as $elem) {
                    $elem = trim($elem);
                    if (!preg_match("/^http/", $elem) && !preg_match("/^\/\/\w/", $elem) && !preg_match("/^\/\w/", $elem)) {
                        array_splice($this->data[$key][$num], array_search($elem, $this->data[$key][$num]), 1);
                    } else if (preg_match("/^\/\/\w/", $elem)) {
                        $this->data[$key][$num][array_search($elem, $this->data[$key][$num])] = "https:" . $elem;
                    } else if (preg_match("/^\/\w/", $elem)) {
                        $this->data[$key][$num][array_search($elem, $this->data[$key][$num])] = $this->url . "\file" . $elem;
                    } else {
                        $this->data[$key][$num][array_search($elem, $this->data[$key][$num])] = trim($elem);
                    }
                }
            }
        }
        echo "Node data retrieved" . PHP_EOL;
        $this->sortUrl();
    }

    protected function readLink(): void
    {
        $data = $this->nodeAttr('a', 'href', $this->crawler->html());
        $failed = [];
        
        foreach ($data as $elem) {
            $this->getPage();
            if (preg_match("/^\/\w/", $elem)) {
                // array_push($this->link, $this->url.$elem);
                try {
                    $this->request($this->url . $elem, true);
                    $this->readAttr();
                } catch (\Throwable $th) {
                    array_push($failed, $th->getMessage());
                    echo $th->getMessage() . PHP_EOL;
                }
            }
        }
    }

    protected function sortUrl(): void // check if url is file or link
    {
        echo "Sorting Urls" . PHP_EOL;
        $local = ['file' => [], 'url' => []];
        $external = ['file' => [], 'url' => []];
        foreach ($this->data as $key => $value) {
            foreach ($value as $num => $arr) {
                foreach ($arr as $elem) {
                    if (str_contains($elem, $this->url)) {
                        if (preg_match("/\/\/[^\/]+\/[^\.]+\./", $elem)) {
                            array_push($local['file'], $elem);
                        } else {
                            array_push($local['url'], $elem);
                        }
                    } else if (preg_match("/\/[^\/]+\/[^\.]+\./", $elem)) {
                        array_push($external['file'], $elem);
                    } else {
                        array_push($external['url'], $elem);
                    }
                }
            }
        }
        echo "Url sorted" . PHP_EOL;
        print_r($this->getFiles($local['file']));
        print_r($this->getFiles($external['file'], true));
        print_r($this->getUrl($external['url']));
    }

    protected function getFiles(array $urls, bool $check = false): array
    {
        echo "Downloading files" . PHP_EOL;
        $failed = [];
        $short_url = preg_filter("/\..*/", '', preg_filter("/(.*\/\/)/", '', $this->url));

        if (!$check) {
            foreach ($urls as $url) {
                // echo "Attempting to download ".$url.PHP_EOL;
                try {
                    $file = preg_filter("/[^\/]+:\/\//", '', preg_replace("/\?.*/", '', $url));
                    if ($this->fs->exists(Path::canonicalize(".\public\local/".$file))) {
                        throw new Exception('Resource already exists');
                    }
                    $data = $this->httpRequest($url)->getContent();
                    if (preg_match("/^\<html/", $data)) {
                        throw new Exception('Resource not available');
                    }
                    $this->fs->dumpFile(Path::canonicalize(".\public\local/" . $file), $data);
                    // echo $url." resolved".PHP_EOL; echo PHP_EOL;
                } catch (\Throwable $th) {
                    // echo $th->getMessage().PHP_EOL; echo PHP_EOL;
                    array_push($failed, $th->getMessage());
                }
            }
        } else if ($check) {
            foreach ($urls as $url) {
                // echo "Checking external ".$url.PHP_EOL;
                try {
                    $file = preg_filter("/[^\/]+:\/\//", '', preg_replace("/\?.*/", '', $url));
                    if ($this->fs->exists(Path::canonicalize(".\public\seed/".$file))) {
                        throw new Exception('Resource already exists');
                    }
                    $data = $this->httpRequest($url)->getContent();
                    if (preg_match("/^\<html/", $data)) {
                        throw new Exception('Resource not available');
                    } else if (str_contains($data, $short_url)) {
                        // echo "Attempting ".$url.PHP_EOL;
                        array_push($this->link, $file);
                        $this->fs->dumpFile(Path::canonicalize(".\public\seed/" . $file), $data);
                        // echo $url . " resolved".PHP_EOL; echo PHP_EOL;
                    }
                } catch (\Throwable $th) {
                    // echo $th->getMessage().PHP_EOL; echo PHP_EOL;
                    array_push($failed, $th->getMessage());
                }
            }
        }
        return $failed;
    }

    protected function getUrl(array $urls): array
    {
        $failed = [];
        $short_url = preg_filter("/\..*/", '', preg_filter("/(.*\/\/)/", '', $this->url));

        foreach ($urls as $url) {
            try {
                $file = preg_filter("/[^\/]+:\/\//", '', $url) . ".js";
                if ($this->fs->exists(Path::canonicalize(".\public\seed/" . $file))) {
                    throw new Exception('Resource already exists');
                }
                $data = $this->httpRequest($url)->getContent();
                if (str_contains($data, $short_url)) {
                    $this->fs->dumpFile(Path::canonicalize(".\public\seed/" . $file), $data);
                }

            } catch (\Throwable $th) {
                array_push($failed, $th->getMessage());
            }
        }
        return $failed;
    }

    protected function getPage(?string $file = null): void
    {
        $short_url = preg_filter("/\..*/", '', preg_filter("/(.*\/\/)/", '', $this->url));
        $v = $this->crawler->html();
        $v = str_replace($this->url, Path::canonicalize(".\public\local/" . $short_url), $v);
        foreach ($this->link as $link) {
            $v = str_replace($link, Path::canonicalize(".\public\seed/" . $link), $v);
        }
        $fileHandler = $file . '.htm' ?: 'index.htm';
        $this->fs->dumpFile(Path::canonicalize(".\public/" . $fileHandler), $v);
        $this->data = [];
        $this->link = [];
    }

    protected function close(): void
    {
        echo "Closing crawler" . PHP_EOL;
        // assert all function have been met before closing
        $this->client->close();
    }

    protected function nodeAttr(string $tag, string $attr, string $doc): array
    {
        echo "Fetching attributes for " . $tag . PHP_EOL;
        $pattern = "/\<" . $tag . "\s[^>]*\s" . $attr . "\s*\=\s*[^>]*>/";
        preg_match_all($pattern, $doc, $nodes, PREG_PATTERN_ORDER);
        $pattern1 = ["/\<" . $tag . "\s.*\s" . $attr . "\s*\=\s*\"/", "/\"[^>]*>/"];
        $pattern2 = ["/\<" . $tag . "\s.*\s" . $attr . "\s*\=\s*'/", "/'[^>]*>/"];
        $data = [];
        foreach ($nodes[0] as $node) {
            if (preg_match($pattern1[0], $node)) {
                $n = preg_replace($pattern1[0], '', $node);
                $m = preg_replace($pattern1[1], '', $n);
                array_push($data, trim($m));
            } else if (preg_match($pattern2[0], $node)) {
                $n = preg_replace($pattern2[0], '', $node);
                $m = preg_replace($pattern2[1], '', $n);
                array_push($data, trim($m));
            }
        }
        return $data;
    }
}