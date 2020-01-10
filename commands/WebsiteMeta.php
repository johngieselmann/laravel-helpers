<?php

namespace App\Console\Commands;

use DOMDocument;
use Illuminate\Console\Command;

class WebsiteMeta extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'website:meta
                            {url : The URL of the site you want meta data for}
                            {--output= : The filename of the output.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull website metadata for a site and all its child pages found in the sitemap.';

    /**
     * The progress bar.
     *
     * @var ProgressBar
     */
    protected $progressBar;

    /**
     * The CSV contents that will be sent to the file.
     *
     * @var arr
     */
    protected $siteMeta = [
        ['url', 'title', 'description',],
    ];

    /**
     * The array of website pages from the sitemap.
     *
     * @var arr
     */
    protected $sitePages;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // start the overall counter
        $start = intval(microtime(true));

        $this->setSitePages();

        // start the progress bar
        $this->progressBar = $this->output->createProgressBar(count($this->sitePages));

        // parse the website for the data
        $this->parseWebsiteData();

        // write the results to a CSV
        $this->writeCsv();

        $this->progressBar->finish();
        $end = microtime(true);
        $this->info(' ');
        $this->info('Donezo. Time: ' . ($end - $start));
    }

    private function convert($size)
    {
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
    }

    /**
     * Set the site pages property to be parsed.
     *
     * @return  void
     */
    private function setSitePages()
    {
        $url = $this->argument('url');
        $sitemapPath = $url . '/sitemap.xml';
        $fcSitemap = file_get_contents($sitemapPath);

        // if we can't find the sitemap, we need to just stop
        if (!$fcSitemap) {
            $this->error('Sitemap not found at: ' . $sitemapPath);
            exit;
        }

        // parse the sitemap into an array so we can process it
        $sitemapXml = simplexml_load_string($fcSitemap, "SimpleXMLElement", LIBXML_NOCDATA);
        $sitemapJson = json_encode($sitemapXml);
        $sitemapArr = json_decode($sitemapJson, true);

        // if the sitemap doesn't have the URLs set, we need to just stop
        if (!isset($sitemapArr['url'])) {
            $this->error('Sitemap XML not formatted properly, URLs not found.');
            exit;
        }

        $this->sitePages = $sitemapArr['url'];
    }

    /**
     * Parse the website data and add it to the running CSV array.
     *
     * @return  void
     */
    private function parseWebsiteData()
    {
        // setup the DOM parser for pulling the title
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        // loop through each page in the sitemap and parse the data
        foreach ($this->sitePages as $k => $page) {
            $this->progressBar->advance();

            if (!isset($page['loc'])) {
                continue;
            }

            $pageUrl = $page['loc'];

            // try not to parse static or media pages (pdf, images, etc.)
            if (!preg_match('/\.[a-zA-Z]{2,}$/', $pageUrl)) {

                // setup the defaults in case some parsing fails
                $pageTitle = 'unknown';
                $pageDesc = 'unknown';

                // try to get the metadata for the page
                $meta = get_meta_tags($pageUrl);
                if (isset($meta['description'])) {
                    $pageDesc = $meta['description'];
                }

                // try to get the title of the page, but try to ignore any
                // specific filetypes
                if ($dom->loadHTMLFile($pageUrl)) {
                    $titles = $dom->getElementsByTagName("title");
                    if ($titles->length) {
                        $pageTitle = $titles->item(0)->nodeValue;
                    }
                }

                // if we didn't get it from the DOM reader, try and parse
                // it the old fashioned way
                if ($pageTitle == 'unknown') {

                    $pageContents = file_get_contents($pageUrl);
                    if (strlen($pageContents) > 0) {

                        // ignore
                        $pageContents = trim(preg_replace('/\s+/', ' ', $pageContents));

                        // find the title tag in the page
                        preg_match("/\<title\>(.*)\<\/title\>/i", $pageContents, $matches);

                        // if we have a match, 
                        if (isset($matches[1])) {
                            $pageTitle = $matches[1];
                        }
                    }
                }
            }

            // update the site meta with what we've found
            $this->siteMeta[] = [
                $pageUrl,
                $pageTitle,
                $pageDesc,
            ];

        }
    }

    /**
     * Write all the results to a CSV
     *
     * @return  void
     */
    private function writeCsv()
    {
        // check if we have a specific filename we want to output
        if ($this->option('output')) {
            $fnCsv = $this->option('output');
        } else {
            $parsed = parse_url($this->argument('url'));
            $fnCsv = 'website_meta_' . $parsed['host'] . '_' . date('Y-m-d_H-i-s') . '.csv';
        }

        // push the website metadata to a CSV
        $fhCsv = fopen(storage_path($fnCsv), 'w');
        foreach ($this->siteMeta as $k => $row) {
            fputcsv($fhCsv, $row);
        }
        fclose($fhCsv);
    }
}
