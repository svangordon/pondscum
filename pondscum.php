<?php

$keys = array('Eb'=>'ees', 'Bb'=>'bes', 'C'=>'c', 'F'=>'f');
$clefs = array('treble', 'bass', 'alto', 'tenor');
$layouts = array('letter'=>'"letter"', 'lyre'=>'"b6" \'landscape');
$octaves = array('+2'=> "''", '+1'=>"'", '0'=>'', "-1"=>',','-2'=>',,');
$percussionParts = array('bassDrum', 'snareDrum', 'cowbell');
$instruments = array('bass'=>'tuba', 'melody'=>'trumpet', 'tenor'=>'trombone', 'bassDrum'=>'drums', 'snareDrum'=>'drums', 'cowbell'=>'drums', 'pahs'=>'trombone', 'riffTwo'=>'clarinet', 'harmony'=>'clarinet', 'chordLo'=>'trombone', 'chordMid'=>'baritone sax', 'bari'=>'baritone sax', 'countermelody'=>'alto sax');

function createOutput($lily) {
	$part = $lily['outputoptions']['part'];
	$contents = $lily['source'].$lily['layout'];
	$filename = $lily['file'];
	$includeOption = isset($lily['sourceDirectory'])
		? ' -I ' . escapeshellarg($lily['sourceDirectory'])
		: '';
	$output = "";
	if ($part == 'source') {
		$output =  "$contents";
	} else {
		if($part != 'midi') { $part = 'pdf'; }
		$out = fopen("/tmp/$filename", 'w');
		fwrite($out,$contents);
		fclose($out);
		$temporaryFile = "/tmp/$filename";
		$command = 'lilypond' . $includeOption
			. ' -o ' . escapeshellarg($temporaryFile)
			. ' ' . escapeshellarg($temporaryFile) . ' 2>&1';
		exec($command, $error);
		if (isset($lily['outputoptions']['debug'])) {
			$output = "<pre>$contents<hr>".implode('<br>', $error)."<hr>".print_r($output, 1);
		} else {
			if (!file_exists("/tmp/$filename.$part") || filesize("/tmp/$filename.$part") == 0) {
				error("Lilypond failed. Here is the output:<hr> ".implode("<br>\n", $error));
				return;
			}
			if ($part == 'midi') {
				$output = file_get_contents("/tmp/$filename.midi");
				unlink("/tmp/$filename.midi");
			} else {
				if (!file_exists("/tmp/$filename.pdf")) {
					error("Lilypond failed: $error");
					return;
				}
				$output = file_get_contents("/tmp/$filename.pdf");
				chunlink("/tmp/$filename.pdf");
				chunlink("/tmp/$filename.ps");
				chunlink("/tmp/$filename");
			}
		}
	}
	return $output;
}

function processFile($file, $dir='blo') {
	global $lilydir;
	$lily = array();
	$path = "";
	if(file_exists($file)) {
		$filename=$file;
		$info = pathinfo($file);
		$path = $file;
		$file = $info['filename'].".".$info['extension'];
	} else {
		$filename="$lilydir/$file";
	}
	if (!file_exists("$filename")) {
		error("File $filename does not exist");
		return;
	}
	if (preg_match('/.ly$/', $file)){
		$score = file_get_contents("$filename");
		preg_match("/^%description:\s*(.+)$/im", $score, $description);
		preg_match_all('/%Part: (\w+)/i', $score, $partsmatch);
		preg_match('/title ?=[^"]*"([^"]+)"/', $score, $title);
		preg_match('/tempo(.*)$/m', $score, $tempo);
		if (! isset($title[1])) { print $file; print_r($score); return; }
		$lily['title'] = $title[1];

		$parts = array();

		if (isset($partsmatch[1])) {
			$rawParts = $partsmatch[1];
			foreach ($rawParts as $key => $name) {

				if (preg_match("/^$name\s*=[^{]*{\s*}/m", $score)) {
					// unset($rawParts[$key]);
					continue;
				}
				// Check if part contains underscore
				if (strpos($name, '_') !== false) {
					list($groupName, $subPart) = explode('_', $name, 2);

					if (!isset($parts[$groupName])) {
						$parts[$groupName] = array();
					}

					$parts[$groupName][] = array(
						'name' => $name,
						'label' => $subPart
					);
				} else {
					$parts[$name][] = array(
						'name' => $name,
						'label' => ''
					);
				}
			}
		}
		$lily['parts'] = $parts;
		$lily['tempo'] = isset($tempo[1]) ? $tempo[1] : ' 4 = 100';
		$lily['file'] = $file;
		$lily['path'] = $path;
		$lily['sourceDirectory'] = dirname(realpath($filename));
		$lily['source'] = preg_replace('/\%%?(Generated )?layout.*/si', '', $score);
		$lily['form'] = musicVariableExists($lily['source'], 'form');
		$lily['lyreForm'] = musicVariableExists($lily['source'], 'lyreForm');
		$lily['changes'] = array_search('changes', $lily['parts']) ? 1 : 0;
		$lily['words'] = array_search('words', $lily['parts']);
		$lily['outputoptions'] = array(
			'key'=>'',
			'page'=>'letter',
			'clef'=>'treble',
			'naturalize'=>'true',
			'words'=>'false',
			'octave'=>'0',
		);
		if (isset($description[1])) {
			$lily['description'] = $description[1];
		}
		$lily['dir'] = $dir;
	}
	return $lily;
}

function buildLayout($lily) {
	global $keys, $layouts, $octaves, $instruments, $naturalize_function;
	$part = $lily['outputoptions']['part'];
	$key = isset($lily['outputoptions']['key']) ? $lily['outputoptions']['key'] : "";
	$page = isset($lily['outputoptions']['page']) ? $lily['outputoptions']['page'] : "letter";
	if (! $key) { $key = 'C'; }
	$showwords = isset($lily['outputoptions']['words']) ? $lily['outputoptions']['words'] : "";
	$layout = "%%Generated layout";
	if (isset($lily['outputoptions']['naturalize']) && $lily['outputoptions']['naturalize']) {
		$layout .= $naturalize_function;
	}
	$changes = "";
	if ($lily['changes']) { $changes = "\n\t\t\\transpose ".$keys[$key]." c \\new ChordNames { \\set chordChanges = ##t \\changes }"; }
	$words = "";
	if ($lily['words']) { $words = " \n\t\words"; }
	$tempo = $lily['tempo'];
	$tempoMark = "";
	if ($tempo) {
		$tempoMark = "\n\t\t\t\\tempo $tempo";
	}
	if ($part == 'score'  || $part == 'source' || $part == 'midi') {
		$parts = $lily['parts'];
		$layout .= "\n#(set-default-paper-size ".$layouts[$page].')';
		$layout .= "\n\\pointAndClickOff\n";
		$layout .= "\n\\book {\n\t\\score { <<\n\t\t\t\\set Score.rehearsalMarkFormatter = #format-mark-box-numbers

			";
		if ($part != 'midi') { $layout .= $changes; }
		// Process all parts with the unified structure
		$formAdded = false;
		foreach ($parts as $groupName => $subParts) {
			// Skip special parts
			if ($groupName == 'changes' || $groupName == 'words') {
				continue;
			}
			// Add a comment for the group
			$layout .= "\n\t\t% Group: " . ucwords($groupName);
			foreach ($subParts as $subPart) {
				$partName = $subPart['name'];
				$label = $subPart['label'];
				$instrument = isset($instruments[$groupName]) ? $instruments[$groupName] : 'alto sax';
				$isPercussionPart = isPercussionPart($groupName);
				$clef = $groupName == 'bass' ? 'bass' : ($isPercussionPart ? 'percussion' : 'treble');

				$layout .= "\n\t\t";
				if ($part == 'midi') {
					$layout .= '\unfoldRepeats ';
				}

				// Determine the display name based on whether it's a grouped part
				$displayName = ucwords($groupName);
				if (!empty($label)) {
					$displayName .= " (" . ucwords($label,) . ")";
				}

				$layout .= "\\new " . ($isPercussionPart ? 'DrumStaff' : 'Staff') . " \\with { \\consists \"Volta_engraver\" instrumentName = \"$displayName\" } {";
				if (!$isPercussionPart) {
					$layout .= " \\set Staff.midiInstrument = #\"$instrument\" \\clef $clef";
				}

				$partReference = "\\$partName";
				if ($lily['form'] && !$formAdded) {
					$partReference = "<< \\form { $partReference } >>";
					$formAdded = true;
				}

				$layout .= "$tempoMark\n\t\t\t\\override Score.RehearsalMark.self-alignment-X = #LEFT\n\t\t\t$partReference\n\t\t}";
			}
		}

		$layout .= "\n\t>> \\layout { \\context { \\Score \\remove \"Volta_engraver\" } } ";
		if ($part == 'midi') {
			$layout .= "\n\t\\midi { } ";
			$words = "";
		}
		$layout .= "} $words \n}";
	} else {
		// This is for individual part extraction
		$page = $lily['outputoptions']['page'] ?: "letter";
		$clef = $lily['outputoptions']['clef'] ?: "treble";
		$octave = stripslashes($lily['outputoptions']['octave']);
		$octave = getOctave($key, $part, $clef, $octave);
		$poet = "$key " . ucwords($part);
		$staffspacing = "";
		if ($page == 'lyre') {
			$layout .= "\n#(set-global-staff-size 15)\n";
			$layout .= "\\paper { page-breaking = #ly:one-page-breaking }\n";
			$staffspacing = "\\override Staff.VerticalAxisGroup.minimum-Y-extent = #'(-1 . 1)";
			$changes = "";
		} #else {
		$layout .= "\n#(set-default-paper-size " . $layouts[$page] . ')';
		#}
		$naturalize = "";
		if (isset($lily['outputoptions']['naturalize']) && $lily['outputoptions']['naturalize']) {
			$naturalize = "\\naturalizeMusic ";
		}
		$layout .= "
		\\pointAndClickOff
		\\book {
			\\header{ poet = \"$poet\" }";

		if ($part != 'words') {
			$subParts = array_values($lily['parts'][$part]);


			$layout .= "
					\\score { <<
						$changes
						\\set Score.rehearsalMarkFormatter = #format-mark-box-numbers";
			$isPercussionPart = isPercussionPart($part);
			$formAdded = false;
			foreach ($subParts as $subPart) {
				$label = $subPart['label'];
				$instrument = "";
				if (!empty($label)) {
					$instrument = " instrumentName = \" " . ucwords($label,) . "\" ";
				}

				$partName = $subPart['name'];
				if ($page == 'lyre' && musicVariableExists($lily['source'], $partName . 'Lyre')) {
					$partName .= 'Lyre';
				}
				$partReference = "\\" . $partName;
				if ($lily['form'] && !$formAdded) {
					$formName = $page == 'lyre' && $lily['lyreForm'] ? 'lyreForm' : 'form';
					$partReference = "<< \\$formName { $partReference } >>";
					$formAdded = true;
				}

				$layout .= "\n	
					\\new " . ($isPercussionPart ? 'DrumStaff' : 'Staff') . " \\with { \\consists \"Volta_engraver\" $instrument } {
						$tempoMark
						\\override Score.RehearsalMark.self-alignment-X = #LEFT
							$staffspacing
							" . ($isPercussionPart ? '' : "\\clef $clef
						$naturalize \\transpose " . $keys[$key] . " c" . $octave . "
						") . $partReference . "

						}
						";
			}
			$layout .= ">>
						\\layout { \\context { \\Score \\remove \"Volta_engraver\" } }
					} %end score
				";
		}
		if ($showwords || $part == 'words') {
			$layout .= $words;
		}
		$layout .= "\n} %end book";
	}
	$lily['layout'] = $layout;
	$keyname = "";
	if (isset($key)) {
		$keyname = "-$key";
	}
	$lily['filename'] = $lily['title'] . "$keyname-" . ucwords($part);
	return $lily;
}

function musicVariableExists(string $source, string $name): bool
{
	$name = preg_quote($name, '/');
	return preg_match('/^' . $name . '\s*=\s*(?:\\\\[A-Za-z]+\s*)?{/m', $source) === 1;
}

function isPercussionPart(string $part): bool
{
	global $percussionParts;
	return in_array($part, $percussionParts, true);
}

function copySourceIncludes(array $lily, string $destinationDirectory): void
{
	if (!isset($lily['sourceDirectory'])) {
		return;
	}

	preg_match_all('/\\\\include\s+"([^"]+)"/', $lily['source'], $matches);
	$sourceDirectory = realpath($lily['sourceDirectory']);
	if ($sourceDirectory === false) {
		return;
	}

	foreach ($matches[1] as $includePath) {
		$normalizedIncludePath = str_replace('\\', '/', $includePath);
		if (substr($normalizedIncludePath, 0, 1) === '/'
			|| in_array('..', explode('/', $normalizedIncludePath), true)) {
			continue;
		}

		$sourcePath = realpath($sourceDirectory . DIRECTORY_SEPARATOR . $includePath);
		if ($sourcePath === false
			|| !is_file($sourcePath)
			|| strpos($sourcePath, $sourceDirectory . DIRECTORY_SEPARATOR) !== 0) {
			continue;
		}

		$destinationPath = $destinationDirectory . DIRECTORY_SEPARATOR . $includePath;
		$includeDirectory = dirname($destinationPath);
		if (!is_dir($includeDirectory)) {
			mkdir($includeDirectory, 0777, true);
		}
		copy($sourcePath, $destinationPath);
	}
}

function getOctave($key, $part, $clef, $octave) {
	$octave = intval($octave);
	//always transpose up
	if ($key != 'C') {
		$octave++;
	}
	if ($part != 'bass' && $clef == 'bass') {
		$octave--;
	} else if ($part == 'bass' && $clef == 'treble') {
		$octave++;
	}
	$character = $octave < 0 ? "," : "'";

	return str_repeat($character,abs($octave));
//	return $octave;
}

function error($string) {
	print "<b>An error occured:</b> $string\n";
}

function lilysort($a, $b) {
	$acmp = isset($a['title']) ? $a['title'] : $a['file'];
	$bcmp = isset($b['title']) ? $b['title'] : $b['file'];
	return strcasecmp($acmp, $bcmp);
}

function chunlink($file) {
	if (file_exists($file)) {
		unlink($file);
	}
}

$naturalize_function = "
%------------------Code to 'naturalize' music - get rid of double-sharps, E#, etc.-----------------
#(define (naturalize-pitch p)
  (let ((o (ly:pitch-octave p))
        (a (* 4 (ly:pitch-alteration p)))
        ;; alteration, a, in quarter tone steps,
        ;; for historical reasons
        (n (ly:pitch-notename p)))
    (cond
     ((and (> a 1) (or (eq? n 6) (eq? n 2)))
      (set! a (- a 2))
      (set! n (+ n 1)))
     ((and (< a -1) (or (eq? n 0) (eq? n 3)))
      (set! a (+ a 2))
      (set! n (- n 1))))
    (cond
     ((> a 2) (set! a (- a 4)) (set! n (+ n 1)))
     ((< a -2) (set! a (+ a 4)) (set! n (- n 1))))
    (if (< n 0) (begin (set! o (- o 1)) (set! n (+ n 7))))
    (if (> n 6) (begin (set! o (+ o 1)) (set! n (- n 7))))
    (ly:make-pitch o n (/ a 4))))

#(define (naturalize music)
  (let ((es (ly:music-property music 'elements))
        (e (ly:music-property music 'element))
        (p (ly:music-property music 'pitch)))
    (if (pair? es)
       (ly:music-set-property!
         music 'elements
         (map (lambda (x) (naturalize x)) es)))
    (if (ly:music? e)
       (ly:music-set-property!
         music 'element
         (naturalize e)))
    (if (ly:pitch? p)
       (begin
         (set! p (naturalize-pitch p))
         (ly:music-set-property! music 'pitch p)))
    music))

naturalizeMusic =
#(define-music-function (parser location m)
  (ly:music?)
  (naturalize m))
%-----------------End Naturalization code---------------
";

?>
