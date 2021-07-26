<?php


	// TODO: UESP specific
require_once("/home/uesp/secrets/wiki.secrets");


class CPopularPageCountsVarnishLogParser 
{
	public $SHOW_PROGRESS_LINECOUNT = 10000;	// Show parsing progress every X lines
	public $SHOW_UNMATCHED_LINES = false;		// Prints message to stdout if line is not parsed to a wiki page view (WARNING: Noisy)
	public $MIN_COUNT_FOR_DATABASE = 5;			// Only save pages with this number of counts or higher in the database
	
	public $BLACKLIST_NAMESPACES = array(		// List of regexes used to ignore parsed wiki pages by their namespace
			'/https$/i',
	);
	
	protected $currentLineNumber = 1;
	protected $totalWikiViewsFound = 0;
	protected $totalWikiSpecialsFound = 0;
	protected $actionTypes = [];
	protected $currentLineDate = null;
	protected $firstLineDate = null;
	protected $lastLineDate = null;
	
	protected $pageCounts = [];
	protected $badUrlCounts = [];
	protected $summaryCounts = [];
	
	protected $db = null;
	
	protected $lastParseTimestamp = -1;
	
	
	public function __construct()
	{
		$this->LoadInfoFromDatabase();
	}
	
	
	protected function GetDatabase()
	{
		global $uespWikiDB;
		global $uespWikiUser;
		global $uespWikiPW;
		global $UESP_SERVER_DB1;
		
		if ($this->db) return $this->db;
		
		$this->db = new mysqli($UESP_SERVER_DB1, $uespWikiUser, $uespWikiPW, $uespWikiDB);
		
		if ($this->db->connect_errno)
		{
			throw new RuntimeException('MySQL connection error: ' . $this->db->connect_error);
			$this->db = null;
			return false;
		}
		
		return $this->db;
	}
	
	
	protected function SaveCountsToDatabase()
	{
		$db = $this->GetDatabase();
		if (!$db) return false;
		
		foreach ($this->pageCounts as $pageDate => $pageCounts)
		{
			$safeDate = $db->real_escape_string($pageDate);
			
			foreach ($pageCounts as $title => $pageCount)
			{
				if ($pageCount < $this->MIN_COUNT_FOR_DATABASE) continue;
				
				$safeName = $db->real_escape_string($title);
				$query = "INSERT INTO popularPageCounts(pageName, pageDate, pageCount) VALUES('$safeName', '$safeDate', '$pageCount') ON DUPLICATE KEY UPDATE pageCount=pageCount+'$pageCount';";
				$result = $db->query($query);
				if ($result === false) print("\tError: Failed to save count to database!\n\t{$this->db->error}\n");
			}
		}
		
		return true;
	}
	
	
	protected function SaveSummariesToDatabase()
	{
		$db = $this->GetDatabase();
		if (!$db) return false;
		
		foreach ($this->summaryCounts as $pageDate => $summaryCounts)
		{
			$safeDate = $db->real_escape_string($pageDate);
			
			foreach ($summaryCounts as $title => $pageCount)
			{
				if ($pageCount < $this->MIN_COUNT_FOR_DATABASE) continue;
				
				$safeName = $db->real_escape_string($title);
				$query = "INSERT INTO popularPageSummaries(pageName, pageDate, pageCount) VALUES('$safeName', '$safeDate', '$pageCount') ON DUPLICATE KEY UPDATE pageCount=pageCount+'$pageCount';";
				$result = $db->query($query);
				if ($result === false) print("\tError: Failed to save summary to database!\n\t{$this->db->error}\n");
			}
		}
		
		return true;
	}
	
	
	protected function SaveInfoToDatabase()
	{
		$db = $this->GetDatabase();
		if (!$db) return false;
		
		if ($this->lastLineDate)
		{
			$lastDate = $this->lastLineDate->getTimestamp();
			$safeDate = $db->real_escape_string($lastDate);
			$query = "INSERT INTO popularPageInfo(k, v) VALUES('lastParseTimestamp', '$safeDate') ON DUPLICATE KEY UPDATE v='$safeDate';";
			$result = $db->query($query);
			if ($result === false) print("\tError: Failed to update lastParseTimestamp info to database!\n\t{$this->db->error}\n");
		}
		
		return true;
	}
	
	
	protected function LoadInfoFromDatabase()
	{
		$db = $this->GetDatabase();
		if (!$db) return false;
		
		$query = "SELECT * FROM popularPageInfo;";
		$result = $db->query($query);
		
		if ($result === false) 
		{
			print("\tError: Failed to load  popularPageInfo from database!\n\t{$this->db->error}\n");
			return false;
		}
		
		while ($row = $result->fetch_assoc())
		{
			$key = $row['k'];
			$value = $row['v'];
			
			if ($key == "lastParseTimestamp")
				$this->lastParseTimestamp = intval($value);
		}
		
		return true;
	}
	
	
	public function SaveToDatabase()
	{
		$result = true;
		
		$result &= $this->SaveCountsToDatabase();
		$result &= $this->SaveSummariesToDatabase();
		$result &= $this->SaveInfoToDatabase();
		
		return $result;
	}
	
	
	protected function IsNamespaceBlacklist($namespace)
	{
		foreach ($this->BLACKLIST_NAMESPACES as $blacklist)
		{
			if (preg_match($blacklist, $namespace)) return true;
		}
		
		return false;
	}
	
	
	protected function IsValidAction($action)
	{
		if ($action ==  "GET") return true;
		if ($action == "POST") return true;
		
		return false;
	}
	
	
	protected function DecodeUrl($url)
	{
		$decodeUrl = urldecode($url);
		return $decodeUrl;
	}
	
	
	protected function ParseDate($date)
	{
			// TODO: Depends on date format used in log 
			// Varnish: 22/Jul/2021:12:33:31 -0400
		$newDate = date_create_from_format("d/M/Y:H:i:s O", $date);
		return $newDate;
	}
	
	
	protected function ParseUrl($url)
	{
		$url = $this->DecodeUrl($url);
		
		$urlParts = parse_url($url);
		if ($urlParts === false) return false;
		
		parse_str($urlParts['query'], $queryParams);
		
		$urlParts['fullpath'] = $urlParts['path'];
		$urlParts['queryparams'] = $queryParams;
		$urlParts['wikipage'] = '';
		$urlParts['wikinamespace'] = '';
		$urlParts['wikiarticle'] = '';
		$urlParts['wikispecial'] = '';
		$urlParts['domain'] = $urlParts['host'];
		
			//TODO: This is UESP specific
		if ($urlParts['host'] == "localhost" || preg_match('/uesp\.net$/i', $urlParts['host']))
		{
			if (preg_match('#^/wiki/#', $urlParts['path']))
			{
				$title = preg_replace('#^/wiki/#', "", $urlParts['path']);
				if ($title == "") $title = "Main_Page";
				$urlParts['wikipage'] = trim($title);
			}
			else if ($urlParts['path'] == '/w/index.php')
			{
				$title = $queryParams['title'];
				if ($title == "") $title = "Main_Page";
				if ($title) $urlParts['wikipage'] = trim($title);
			}
			else if ($urlParts['path'] == '/w/load.php')
			{
				$urlParts['wikispecial'] = "load.php";
			}
			else if ($urlParts['path'] == '/w/api.php')
			{
				$urlParts['wikispecial'] = "api.php";
			}
			
			if ($urlParts['wikipage'])
			{
				$urlParts['wikipage'] = preg_replace('/_Talk$/', ' talk', $urlParts['wikipage']);
				$urlParts['wikipage'] = preg_replace('/ Talk$/', ' talk', $urlParts['wikipage']);
				$urlParts['wikipage'] = str_replace('_', ' ', $urlParts['wikipage']);
				$urlParts['wikipage'] = ucwords($urlParts['wikipage']);
				
				$pageParts = explode(":", $urlParts['wikipage'], 2);
				
				if ($pageParts[1] != null)
				{
					$urlParts['wikinamespace'] = trim($pageParts[0]);
					$urlParts['wikiarticle'] = trim($pageParts[1]);
				}
				else
				{
					$urlParts['wikinamespace'] = '';
					$urlParts['wikiarticle'] = trim($pageParts[0]);
				}
			}
		}
		
		return $urlParts;
	}
	
	
	public function SavePageCountsFile($filename)
	{
		
		foreach ($this->pageCounts as $pageDate => $pageCounts)
		{
			asort($pageCounts);
			
			$file = fopen($filename . "." . $pageDate, "a");
			if ($file === false) return false;
			
			foreach ($pageCounts as $title => $count)
			{
				fwrite($file, "$count $title\n");
			}
			
			fclose ($file);
		}
		
		return true;
	}
	
	
	public function SaveSummaryCountsFile($filename)
	{
		
		foreach ($this->summaryCounts as $pageDate => $summaryCounts)
		{
			asort($summaryCounts);
			
			$file = fopen($filename . "." . $pageDate, "a");
			if ($file === false) return false;
			
			foreach ($summaryCounts as $title => $count)
			{
				fwrite($file, "$count $title\n");
			}
			
			fclose ($file);
		}
		
		return true;
	}
	
	
	protected function SavePageView($url, $date)
	{
		$urlParts = $this->ParseUrl($url);
		
		if ($urlParts === false)
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Failed to parse url: $url\n");
			return false;
		}
		
		$pageDate = $date->format("Y-m-d");
		
		$title = $urlParts['wikipage'];
		$special = $urlParts['wikispecial'];
		$namespace = $urlParts['wikinamespace'];
		if ($namespace == '') $namespace = "(Main)";
		
		if ($special != '')
		{
			++$this->totalWikiSpecialsFound;
			$this->summaryCounts[$pageDate]["Other: $special"] += 1;
			return true;
		}
		else if ($title == '')
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Url is not a wiki page: $url\n");
			return false;
		}
		
		if ($this->IsNamespaceBlacklist($namespace))
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Namespace '$namespace' is blacklisted: $url\n");
			return false;
		}
		
		++$this->totalWikiViewsFound;
		$this->pageCounts[$pageDate][$title] += 1;
		$this->summaryCounts[$pageDate][$namespace] += 1;
		$this->summaryCounts[$pageDate]['(All)'] += 1;
		
		if (preg_match('/ Talk$/i', $namespace))
		{
			$this->summaryCounts[$pageDate]['(Talk)'] += 1;
		}
		
		$this->summaryCounts[$pageDate]["Domain: " . $urlParts['domain']] += 1;
		
		return true;
	}
	
	
	protected function ParseLogLine($line)
	{
			// TODO: This depends on the exact log format used
		$isMatched = preg_match('/(.*) \[(.*)\] "([A-Za-z0-9\-_]+) (.*) HTTP.*" (.*) (.*)/', $line, $matches);
		
		if (!$isMatched)
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Line not matched: $line");
			return false;
		}
		
		$ipAddress = $matches[1];
		$time = $matches[2];
		$action = $matches[3];
		$url = $matches[4];
		$httpCode = $matches[5];
		$contentLength = $matches[6];
		
		$date = $this->ParseDate($time);
		$this->currentLineDate = $date;
		
		if (!$date)
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Failed to parse time string: $time\n");
			return false;
		}
		
		if (!$this->firstLineDate) $this->firstLineDate = $date;
		if (!$this->lastLineDate) $this->lastLineDate = $date;
		if ($this->lastLineDate && $date > $this->lastLineDate) $this->lastLineDate = $date;
		
		$timestamp = $date->getTimestamp();
		
		if ($this->lastParseTimestamp > 0 && $timestamp <= $this->lastParseTimestamp)
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Skipping due to old timestamp!\n");
			return false;
		}
		
		$this->actionTypes[$action] += 1;
		
		if (!$this->IsValidAction($action))
		{
			if ($this->SHOW_UNMATCHED_LINES) print("\t{$this->currentLineNumber}: Invalid action '$action': $line");
			return false;
		}
		
		return $this->SavePageView($url, $date);
	}
	
	
	public function ParseLog($filename)
	{
		print("PopularPageCounts: Parsing log file '$filename'...\n");
		
		$file = fopen($filename, "r");
		if ($file === false) return false;
		
		$this->currentLineNumber = 1;
		$this->firstLineDate = null;
		
		while (($line = fgets($file)) !== false)
		{
			if ($this->SHOW_PROGRESS_LINECOUNT > 0 && $this->currentLineNumber % $this->SHOW_PROGRESS_LINECOUNT == 0)
			{
				print("\t{$this->currentLineNumber}: Parsing Line...\n");
			}
			
			$this->ParseLogLine($line);
			
			++$this->currentLineNumber;
		}
		
		if ($this->firstLineDate && $this->lastLineDate)
		{
			$date1 = date_format($this->firstLineDate, "r");
			$date2 = date_format($this->lastLineDate, "r");
			$diff = $this->lastLineDate->getTimestamp() - $this->firstLineDate->getTimestamp();
			print("Found $diff seconds of logs from $date1 to $date2.\n");
		}
		else
		{
			print("Failed to parse first and/or last line date field!\n");
		}
		
		print("Found a total of {$this->totalWikiViewsFound} wiki views and {$this->totalWikiSpecialsFound} special views in {$this->currentLineNumber} lines of log data.\n");
		
		fclose($file);
		return true;
	}
	
	
};


