<?php

# collectd related functions

require_once 'conf/common.inc.php';

# returns an array of all collectd hosts
function collectd_hosts() {
	global $CONFIG;

	if (!is_dir($CONFIG['datadir']))
		return false;

	$dir = array_diff(scandir($CONFIG['datadir']), array('.', '..'));
	foreach($dir as $k => $v) {
		if(!is_dir($CONFIG['datadir'].'/'.$v))
			unset($dir[$k]);
	}
	return($dir);
}

function rrd_host_search() {
	
}

/**
 * Builds the search string to find the RRD files and returns a list of filenames, including
 * the /datadir/ path.  There are a few use cases:
 * 
 * #1 - Searching for all RRD files.  All arguments will be NULL other then $datadir.
 * 
 * #2 - The process of searching for RRD files for a host, plugin, or type. The category, pinstance and
 * tinstance arguments should be left off, and one or all of host / plugin / type should be set.  
 * 
 * #3 - Searching very specifically for files matching category/pinstance/tinstance.
 * 
 * @param boolean $return_full_path
 * @param string $datadir
 * @param string $host
 * @param string $plugin
 * @param string $type
 * @param string $category
 * @param string $pinstance
 * @param string $tinstance
 */
function rrd_file_search(
		$return_full_path,
		$datadir, 
		$host = NULL, 
		$plugin = NULL, 
		$type = NULL, 
		$category = NULL, 
		$pinstance = NULL, 
		$tinstance = NULL
	) {
	global $CONFIG;
	if ($CONFIG['debug']) error_log(sprintf('DEBUG: rrd_file_search(rfp=[%s],ddir=[%s],host=[%s],plugin=[%s],type=[%s],cat=[%s],pi=[%s],ti=[%s])', 
	$return_full_path ? 'true' : 'false',
	$datadir,
	$host,
	$plugin,
	$type,
	$category,
	$pinstance,
	$tinstance
	));
	
	# check for trailing slash on $datadir, remove it if found
	if (substr($datadir,strlen($datadir) == '/')) $datadir = substr($datadir, 0, strlen($datadir)-1);
	if ($CONFIG['debug']) error_log(sprintf('DEBUG: $datadir=[%s]', $datadir));
		
	# If all arguments after $datadir are NULL, then we should just glob for all RRD files
	if ((!strlen($host)) && (!strlen($plugin)) && (!strlen($type)) &&
		(!strlen($category)) && (!strlen($pinstance)) && (!strlen($tinstance))) {
		$files = glob($datadir . '/*/*.rrd');
		if ($CONFIG['debug']) error_log(sprintf('DEBUG: RETURN LIST OF %s FILES', count($files)));
		return $files;
	}
	
	# Optional arguments which are always found need to be set to wildcards if not set
	if (!strlen($host)) $host = '*';
	if (!strlen($plugin)) $plugin = '*';
	if (!strlen($type)) $type = '*';

	# by customizing the file search by plugin type, we can avoid searching directories
	# which are not interesting to this instance of the function
	switch ($plugin) {
		
		# Simple plugins, always a single RRD file named after the plugin
		# /(datadir)/(host)/(plugin)/(plugin).rrd
		case 'load':
		case 'users':
		case 'uptime':
			$file_glob = sprintf('%s/%s/%s/%s.rrd',
				$datadir,
				$host, 
				$plugin,
				$plugin
			);
			$path_prefix_len = strlen($datadir . '/' . $host . '/');
			break;
		
		# Handles most other plugins
		# /(datadir)/(host)/(plugin)[-(category)][-(pinstance)]/(type)[-tinstance]*.rrd
		# category, pinstance, and tinstance are optional
		default:
			$file_glob = sprintf('%s/%s/%s%s%s%s%s/%s%s%s%srrd',
				$datadir,
				$host, 
				$plugin,
				strlen($category) ? '-' : '',
				$category,
				strlen($pinstance) ? '-' : '',
				$pinstance,
				$type,
				strlen($tinstance) ? '-' : '', 
				$tinstance,
				strlen($tinstance) ? '.' : '[-.]*'
			);
			$path_prefix_len = strlen($datadir . '/' . $host . '/');
	}
	
	if ($CONFIG['debug']) error_log(sprintf('DEBUG: glob([%s])', $file_glob));
	$files = glob($file_glob);
	
	# Strip the /datadir/hostname/ off the front
	if ($return_full_path == false) {
		foreach ($files as $key => $filename) {
			$files[$key] = substr($filename, $path_prefix_len);
		}
	}	
	
	if ($CONFIG['debug']) error_log(sprintf('DEBUG: RETURN $files=[%s]', serialize($files)));
	return $files;
}

# returns an array of plugins/pinstances/types/tinstances
function collectd_plugindata($host, $plugin=NULL) {
	global $CONFIG;
	if ($CONFIG['debug']) error_log(sprintf('DEBUG: collectd_plugindata($host=[%s],$plugin=[%s])', $host, $plugin));

	if (!is_dir($CONFIG['datadir'].'/'.$host))
		return false;

	chdir($CONFIG['datadir'].'/'.$host);
	
	# search for plugins for this host, and optionally a specific plugin
	$files = rrd_file_search(
			false,
			$CONFIG['datadir'],
			$host,
			$plugin
		);
	
	if (!$files)
		return false;
	
	if (($plugin == null) && ($CONFIG['debug'])) error_log(sprintf('DEBUG: $files=[%s]', serialize($files)));
	if ((strpos($plugin, 'snmp') !== FALSE) && ($CONFIG['debug'])) error_log(sprintf('DEBUG: $files=[%s]', serialize($files)));

	$data = array();
	foreach($files as $filename) {
		if ((strpos($filename, 'snmp') !== FALSE) && ($CONFIG['debug'])) error_log(sprintf('DEBUG: $filename=[%s]', $filename));
		
		switch ($plugin) {
			
			case 'snmp':
				# SNMP RRD filenames can have optional InstancePrefix, which should be used as pi= value
				# /(datadir)/(host)/snmp/(type)-
				# /(datadir)/(host)/snmp/(type)-(instanceprefix)-
				
				# '#([\w_]+)(?:\-(.+))?/([\w_]+)(?:\-(.+))?\.rrd#',
				preg_match('`
					(?P<p>[\w_]+)      # plugin
					(?:\-(?P<c>[\w]+)) # category
					(?:\-(?P<pi>.+))?  # plugin instance
					/
					(?P<t>[\w_]+)      # type
					(?:\-(?P<ti>.+))?  # type instance
					\.rrd
					`x',
					$filename, $matches);
				
			default:
				preg_match('`
					(?P<p>[\w_]+)      # plugin
					(?:(?<=varnish)(?:\-(?P<c>[\w]+)))? # category
					(?:\-(?P<pi>.+))?  # plugin instance
					/
					(?P<t>[\w_]+)      # type
					(?:\-(?P<ti>.+))?  # type instance
					\.rrd
				`x', $filename, $matches);
		}

		$data[] = array(
			'p'  => $matches['p'],
			'c'  => isset($matches['c']) ? $matches['c'] : '',
			'pi' => isset($matches['pi']) ? $matches['pi'] : '',
			't'  => $matches['t'],
			'ti' => isset($matches['ti']) ? $matches['ti'] : '',
		);

		if ((strpos($filename, 'snmp') !== FALSE) && ($CONFIG['debug'])) error_log(sprintf('DEBUG: $data=[%s]', serialize($data)));
	}

	# only return data about one plugin
	if (!is_null($plugin)) {
		$pdata = array();
		foreach($data as $item) {
			if ($item['p'] == $plugin)
				$pdata[] = $item;
		}
		$data = $pdata;		
	}

	if ((strpos($plugin, 'snmp') !== FALSE) && ($CONFIG['debug'])) error_log(sprintf('DEBUG: RETURNED $data=[%s]', serialize($data)));
	return($data);
}

# returns an array of all plugins of a host
function collectd_plugins($host) {
	$plugindata = collectd_plugindata($host);

	$plugins = array();
	foreach ($plugindata as $item) {
		if (!in_array($item['p'], $plugins))
			$plugins[] = $item['p'];
	}

	return $plugins;
}

# returns an array of all pi/t/ti of an plugin
function collectd_plugindetail($host, $plugin, $detail, $where=NULL) {
	$details = array('pi', 'c', 't', 'ti');
	if (!in_array($detail, $details))
		return false;

	$plugindata = collectd_plugindata($host);

	$return = array();
	foreach ($plugindata as $item) {
		if ($item['p'] == $plugin && !in_array($item[$detail], $return) && isset($item[$detail])) {
			if ($where) {
				$add = true;
				# add detail to returnvalue if all where is true
				foreach($where as $key => $value) {
					if ($item[$key] != $value)
						$add = false;
				}
				if ($add)
					$return[] = $item[$detail];
			} else {
				$return[] = $item[$detail];
			}
		}
	}

	if (empty($return))
		return false;

	return $return;
}

# group plugin files for graph generation
function group_plugindata($plugindata) {
	global $CONFIG;

	$data = array();
	# type instances should be grouped in 1 graph
	foreach ($plugindata as $item) {
		# backwards compatibility
		if ($CONFIG['version'] >= 5 || !preg_match('/^(df|interface)$/', $item['p']))
			if($item['p'] != 'libvirt')
				unset($item['ti']);
		$data[] = $item;
	}

	# remove duplicates
	$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

	return $data;
}

function plugin_sort($data) {
	if (empty($data))
		return $data;

	foreach ($data as $key => $row) {
		$pi[$key] = (isset($row['pi'])) ? $row['pi'] : null;
		$c[$key]  = (isset($row['c']))  ? $row['c'] : null;
		$ti[$key] = (isset($row['ti'])) ? $row['ti'] : null;
		$t[$key]  = (isset($row['t']))  ? $row['t'] : null;
	}

	array_multisort($c, SORT_ASC, $pi, SORT_ASC, $t, SORT_ASC, $ti, SORT_ASC, $data);

	return $data;
}

# generate graph url's for a plugin of a host, outputs the HTML tags to display the graphs
function graphs_from_plugin($host, $plugin, $overview=false) {
	global $CONFIG;

	$plugindata = collectd_plugindata($host, $plugin);
	$plugindata = group_plugindata($plugindata);
	$plugindata = plugin_sort($plugindata);

	foreach ($plugindata as $items) {

		if (
			$overview && isset($CONFIG['overview_filter'][$plugin]) &&
			$CONFIG['overview_filter'][$plugin] !== array_intersect_assoc($CONFIG['overview_filter'][$plugin], $items)
		) {
			continue;
		}

		$items['h'] = $host;

		$time = array_key_exists($plugin, $CONFIG['time_range'])
			? $CONFIG['time_range'][$plugin]
			: $CONFIG['time_range']['default'];

		if ($CONFIG['graph_type'] == 'canvas') {
			chdir($CONFIG['webdir']);
			isset($items['p']) ? $_GET['p'] = $items['p'] : $_GET['p'] = '';
			isset($items['pi']) ? $_GET['pi'] = $items['pi'] : $_GET['pi'] = '';
			isset($items['t']) ? $_GET['t'] = $items['t'] : $_GET['t'] = '';
			isset($items['ti']) ? $_GET['ti'] = $items['ti'] : $_GET['ti'] = '';
			include $CONFIG['webdir'].'/plugin/'.$plugin.'.php';
		} else {
			if ($CONFIG['graph_type'] == 'svg') {
				$svg_upscale_magic_number = 1.114;
				$img_width = sprintf('width="%s"', (is_numeric($CONFIG['detail-width']) ? ($CONFIG['detail-width']) : 400) * $svg_upscale_magic_number);
			} else {
				$img_width = '';
			}
			printf('<a href="%s%s"><img class="rrd_graph" src="%s%s" %s></a>'."\n",
				$CONFIG['weburl'],
				build_url('detail.php', $items, $time),
				$CONFIG['weburl'],
				build_url('graph.php', $items, $time),
				$img_width
			);
		}
	}
}

# generate an url with GET values from $items
function build_url($base, $items, $s=NULL) {
	global $CONFIG;

	if (!is_array($items))
		return false;

	if (!is_numeric($s))
		$s = $CONFIG['time_range']['default'];

	$i=0;
	foreach ($items as $key => $value) {
		# don't include empty values
		if ($value == 'NULL')
			continue;

		$base .= sprintf('%s%s=%s', $i==0 ? '?' : '&', $key, $value);
		$i++;
	}
	if (!isset($items['s']))
		$base .= '&s='.$s;

	return $base;
}

# tell collectd to FLUSH all data of the identifier(s)
function collectd_flush($identifier) {
	global $CONFIG;

	if (!$CONFIG['socket'])
		return FALSE;

	if (!$identifier || (is_array($identifier) && count($identifier) == 0) ||
			!(is_string($identifier) || is_array($identifier)))
		return FALSE;

	$u_errno  = 0;
	$u_errmsg = '';
	if ($socket = @fsockopen($CONFIG['socket'], 0, $u_errno, $u_errmsg)) {
		$cmd = 'FLUSH plugin=rrdtool';
		if (is_array($identifier)) {
			foreach ($identifier as $val)
				$cmd .= sprintf(' identifier="%s"', $val);
		} else
			$cmd .= sprintf(' identifier="%s"', $identifier);
		$cmd .= "\n";

		$r = fwrite($socket, $cmd, strlen($cmd));
		if ($r === false || $r != strlen($cmd)) {
			error_log(sprintf('ERROR: Failed to write full command to unix-socket: %d out of %d written',
				$r === false ? -1 : $r, strlen($cmd)));
			return FALSE;
		}

		$resp = fgets($socket);
		if ($resp === false) {
			error_log(sprintf('ERROR: Failed to read response from collectd for command: %s',
				trim($cmd)));
			return FALSE;
		}

		$n = (int)$resp;
		while ($n-- > 0)
			fgets($socket);

		fclose($socket);

		return TRUE;
	} else {
		error_log(sprintf('ERROR: Failed to open unix-socket to collectd: %d: %s',
			$u_errno, $u_errmsg));
		return FALSE;
	}
}

?>
