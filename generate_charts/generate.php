#!/usr/bin/php
<?php
include(__DIR__ . '/../pondscum.php');

$max_processes = 16;

$lilies = array();

array_shift($argv);
$output = preg_replace("/\/+$/", "", array_shift($argv));

if (! $output || $output == '--help' || $output == '-h') {
	echo "Usage: generate.php <output_dir> [lilypond_files]\n";
	exit;
}
if (! is_dir($output)) {
	throw new Exception("Output directory '$output' invalid or does not exist");
}

echo "Output directory: $output\n";
echo "Lilypond files: " . implode(", ", $argv) . "\n";

foreach ($argv as $file) {
	if ($file == 'include.ly' || ! preg_match("/\.ly$/", $file)) {
		continue;
	}
	if (! file_exists($file)) {
		throw new Exception("File '$file' does not exist");
	}
	$lilies[] = processFile(preg_replace("/^\.+\//", "", $file));
}


usort($lilies, 'lilysort');
$index = "";
$pids = array();

foreach ($lilies as $lily) {
	processLily($lily);
}

function launch_job(array &$pids, callable $callback)
{
	global $max_processes;
	global $status;
	while (count($pids) >= $max_processes) {
		$exited = pcntl_wait($status);
		if ($exited > 0) {
			unset($pids[$exited]);
		}
	}
	$pid = pcntl_fork();
	if ($pid == -1) {
		print "could not fork\n";
	} else if ($pid) {
		$pids[$pid] = true;
	} else {
		ob_start();
		$callback();
		print ob_get_clean();
		exit(0);
	}
}

function processLily(array $lily)
{
	global $output, $keys, $clefs, $layouts, $musicdir;

	$pids = array();

	$musicdir = "";
	if ($lily) {
		#set current date as tagline
		$lily['source'] = preg_replace("/^\s+tagline ?=.*$/m", "", $lily['source']);
		$lily['source'] = preg_replace("/\\\header\s*{/", "\\header { \n\ttagline = " . date('"n/j/Y"'), $lily['source']);

		$title = str_replace(" ", "_", $lily['title']);
		$musicdir = "$output";
		$dir = "$musicdir" . DIRECTORY_SEPARATOR . $title;
		print getcwd() . "\n";
		print "$dir\n";
		if (is_dir($dir)) {
			rrmdir($dir);
		}
		mkdir($dir);
		print "$title: \n";
		foreach (array('midi', 'score', 'source') as $part) {
			print "$part\n ";
			mkdir("$dir" . DIRECTORY_SEPARATOR . $part);
			$lily['outputoptions']['key'] = "";

			launch_job($pids, function () use ($lily, $title, $part) {
				// Use the modified $lily['file'] in child implicitly by modification
				// But we need to ensure unique filenames if multiple processes run
				$lily['file'] = str_replace('.ly', '', $lily['file']) . "_" . getmypid() . ".ly";
				generateFile($lily, $title, $part);
			});
		}

		if (isset($lily['description'])) {
			file_put_contents("$dir/description.txt", $lily['description']);
		}

		foreach (array_keys($lily['parts']) as $part) {
			$lily['outputoptions']['part'] = $part;
			$lily['outputoptions']['key'] = null;
			$lily['outputoptions']['clef'] = null;

			if ($part == 'words' || $part == 'lyrics') {
				mkdir("$dir/$part");
				launch_job($pids, function () use ($lily, $title, $part) {
					$lily['file'] = str_replace('.ly', '', $lily['file']) . "_" . getmypid() . ".ly";
					generateFile($lily, $title, $part);
				});
			} else if ($part == 'bassDrum') {
				mkdir("$dir/$part");
				$lily['outputoptions']['key'] = 'C';
				$lily['outputoptions']['clef'] = 'percussion';
				$lily['outputoptions']['page'] = 'letter';
				$lily['outputoptions']['octave'] = 0;
				launch_job($pids, function () use ($lily, $title, $part) {
					$lily['file'] = str_replace('.ly', '', $lily['file']) . "_" . getmypid() . ".ly";
					$filename = generateFile($lily, $title, $part);
					print "\t$filename\n";
				});
			} else {
				mkdir("$dir/$part");
				foreach (array_keys($keys) as $key) {
					$lily['outputoptions']['key'] = $key;
					foreach ($clefs as $clef) {
						$lily['outputoptions']['clef'] = $clef;
						if ($clef == 'alto' || $clef == 'tenor') {
							continue;
						}
						if ($clef == 'bass' && $lily['outputoptions']['key'] != 'C') {
							continue;
						}
						foreach (array_keys($layouts) as $layout) {
							$lily['outputoptions']['page'] = $layout;
							$lily['outputoptions']['octave'] = 0;

							launch_job($pids, function () use ($lily, $title, $part) {
								$lily['file'] = str_replace('.ly', '', $lily['file']) . "_" . getmypid() . ".ly";
								$filename = generateFile($lily, $title, $part);
								print "\t$filename\n";
							});
						}
						// }
					}
				}
			}
		}

		// Wait for all parts to finish before zipping
		while (count($pids) > 0) {
			$exited = pcntl_wait($status);
			if ($exited > 0) {
				unset($pids[$exited]);
			}
		}

		print "$title.zip\n";
		chdir("$musicdir");
		system("zip -qr \"$title/$title\".zip	\"$title\"");
		chdir("../../");
		print "\n";
	}
}

function generateFile(array $lily, string $title, string $part)
{
	global $musicdir;

	$dir = "$musicdir" . DIRECTORY_SEPARATOR . $title . DIRECTORY_SEPARATOR . $part;
	$lily['outputoptions']['part'] = $part;
	$lily = buildLayout($lily);
	if ($part == 'midi') {
		$ext = 'mid';
	} else if ($part == 'source') { 
		$ext = 'ly';
	} else { $ext = 'pdf'; }
	if ($ext == 'pdf') {
		$title .= "-$part";
		if ($part != 'score') {
			$title .= "-".$lily['outputoptions']['key']."-".$lily['outputoptions']['clef']."_clef-".$lily['outputoptions']['page'];
		}	
	}

	$filename = $dir . DIRECTORY_SEPARATOR . $title . "." . $ext;
	$output = createOutput($lily);
	file_put_contents($filename, $output);
	return $filename;
}

function rrmdir(string $path)
{
	return is_file($path)?
		@unlink($path):
		array_map('rrmdir',glob($path.'/*'))==@rmdir($path)
	;
}

?>
