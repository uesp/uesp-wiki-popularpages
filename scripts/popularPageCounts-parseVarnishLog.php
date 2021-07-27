<?php


	// TODO: UESP specific
require_once("popularPageCounts-parseLog.php");

$parser = new CPopularPageCountsVarnishLogParser();

	/* Modify parameters as needed */
$parser->SHOW_PROGRESS_LINECOUNT = 10000;
$parser->SHOW_UNMATCHED_LINES = false;
$parser->MIN_COUNT_FOR_DATABASE = 5;

	/* Parse logs */
$parser->ParseLog("/var/log/varnish/access.log-20210719");
$parser->ParseLog("/var/log/varnish/access.log-20210720");
$parser->ParseLog("/var/log/varnish/access.log-20210721");
$parser->ParseLog("/var/log/varnish/access.log-20210722");
$parser->ParseLog("/var/log/varnish/access.log-20210723");
$parser->ParseLog("/var/log/varnish/access.log-20210724");
$parser->ParseLog("/var/log/varnish/access.log-20210725");
$parser->ParseLog("/var/log/varnish/access.log-20210726");
$parser->ParseLog("/var/log/varnish/access.log");

	/* Optionally save raw counts to text files */
$parser->SavePageCountsFile("/home/uesp/pagecounts/varnishcounts.txt");
$parser->SaveSummaryCountsFile("/home/uesp/pagecounts/varnishsummary.txt");

	/* Save results to database */
$parser->SaveToDatabase();