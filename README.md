# Pondscum

Pondscum turns an annotated LilyPond source file into a full set of brass-band
charts. It discovers named parts in the source, generates LilyPond layout blocks
for them, invokes LilyPond, and writes a score, MIDI file, generated source,
transposed individual parts, compact lyre charts, and a ZIP archive.

The repository contains two interfaces:

- `generate_charts/generate.php` is the batch generator used by the blocharts
  repository.
- `index.php` is an older web interface that generates one requested artifact
  at a time.

Most current use should go through the batch generator.

## Requirements

- PHP CLI with the `pcntl` extension (`pcntl_fork` and `pcntl_wait`)
- LilyPond available as `lilypond` on `PATH`
- The `zip` command
- A Unix-like environment; batch generation relies on process forking

The output root must already exist and must be writable. Pondscum also writes
temporary LilyPond source and output files under `/tmp`.

## Batch usage

```sh
mkdir -p output
php generate_charts/generate.php output path/to/song.ly
```

Multiple source files may be supplied:

```sh
php generate_charts/generate.php output charts/first.ly charts/second.ly
```

In blocharts, `generate_from_lilypond` is a symlink to the batch generator, so a
typical invocation there is:

```sh
php generate_from_lilypond \
  sheetmusic/current \
  lilypond_source_files/ya_move_you_lose.ly
```

The exact filename `include.ly` is ignored as an input, as is any input without
a `.ly` extension. Other include filenames, such as `form.ily` or `includes.ly`,
have no special meaning.

### Important output-directory behavior

For a title such as `Example Tune`, Pondscum writes to
`<output>/Example_Tune/`. If that song directory already exists, the batch
generator recursively removes it before rebuilding it. Point the generator at
an output root whose song subdirectories are safe to replace.

## LilyPond source contract

Pondscum does not parse LilyPond's syntax tree. It scans the source with regular
expressions, so the following conventions matter.

### Required title

The source must have a quoted title:

```lilypond
\header {
  title = "Example Tune"
}
```

The title determines the output directory and filenames. Spaces become
underscores; other punctuation is preserved.

### Declaring parts

Place a `%part:` marker before or near each named music variable that should be
extracted:

```lilypond
%part: melody
melody = \relative c' {
  c1 | d | e | f |
}

%part: bass
bass = \relative c {
  c1 | d | e | f |
}
```

Part markers are case-insensitive, but names are limited to letters, numbers,
and underscores. A variable detected as an explicitly empty `{ }` block is
omitted.

An underscore groups multiple variables into one logical part. For example:

```lilypond
%part: melody_call
melody_call = { ... }

%part: melody_response
melody_response = { ... }
```

Both variables are placed in the `melody` output. The text after the underscore
becomes the staff label (`Call` and `Response`).

### Optional description and tempo

A one-line description is copied to `description.txt`:

```lilypond
%description: A short description of the chart.
```

Pondscum also captures the text following a LilyPond `tempo` occurrence and
adds that tempo to generated staffs. The usual form is:

```lilypond
\tempo 4 = 120
```

If no tempo is found, it defaults to quarter note = 100.

### Full and lyre forms

If the source defines `form`, Pondscum overlays it on the first staff of the
full score and on each individual letter-sized part. This is where shared
rehearsal marks, repeat structures, and layout breaks can live:

```lilypond
form = {
  \mark \markup \box "A"
  s1*4
}
```

A compact lyre arrangement may define `lyreForm`:

```lilypond
lyreForm = {
  \mark \markup \box "1"
  s1*4
}
```

When generating a lyre chart, Pondscum uses `lyreForm` if it exists; otherwise
it uses `form`. `lyreForm` is only considered when `form` is also defined.

### Lyre-specific part music

For a part named `melody`, a variable named `melodyLyre` overrides its music in
lyre output:

```lilypond
melodyLyre = {
  ...compact arrangement...
}
```

Without that variable, both letter and lyre output use `melody`. Grouped parts
can provide corresponding variables such as `melody_callLyre`.

Together, `form`/`lyreForm` and `<part>Lyre` allow the full chart and the lyre
chart to have different arrangements while retaining the same source file.

### Percussion parts

The following part names receive percussion-specific handling:

- `bassDrum`
- `snareDrum`
- `cowbell`

They are rendered in a LilyPond `DrumStaff`, are not transposed, and receive one
C percussion-clef letter-sized PDF. They do not currently receive a lyre PDF.

### Includes

Relative LilyPond includes work during compilation because Pondscum passes the
source file's directory to LilyPond with `-I`:

```lilypond
\include "form.ily"
```

When Pondscum writes the generated source artifact, it copies directly
referenced include files into the generated `source/` directory while retaining
their relative paths. Absolute includes and paths containing `..` are not
copied.

Include copying is not recursive. If `form.ily` includes another file, that
second-level include is not discovered or copied unless the main `.ly` file also
includes it directly.

### Generated-layout boundary

Before generating output, Pondscum removes an existing generated layout and
everything after it. The boundary may be written as `%layout`, `%%layout`,
`%Generated layout`, or `%%Generated layout`.

This allows an authored source file to retain previously generated layout code
without Pondscum duplicating it on the next run. Musical definitions needed for
generation must appear before that boundary.

## Output matrix

The batch generator creates the following song-level artifacts:

- Full score PDF
- MIDI file with repeats unfolded
- Generated LilyPond source and its direct includes
- Optional `description.txt`
- A ZIP archive containing the generated song directory

For each pitched part it generates ten PDFs:

| Key | Clef | Letter | Lyre |
| --- | --- | --- | --- |
| C | Treble | Yes | Yes |
| C | Bass | Yes | Yes |
| B-flat | Treble | Yes | Yes |
| E-flat | Treble | Yes | Yes |
| F | Treble | Yes | Yes |

Although `alto` and `tenor` appear in the global clef array, the batch generator
currently skips both. Bass-clef output is generated only in C.

A typical output tree is:

```text
output/
  Example_Tune/
    Example_Tune.zip
    description.txt
    midi/
      Example_Tune.mid
    score/
      Example_Tune-score.pdf
    source/
      Example_Tune.ly
      form.ily
    melody/
      Example_Tune-melody-C-treble_clef-letter.pdf
      Example_Tune-melody-C-treble_clef-lyre.pdf
      ...
    bassDrum/
      Example_Tune-bassDrum-C-percussion_clef-letter.pdf
```

## What happens internally

The batch path is:

1. `processFile()` reads the source and discovers its title, description,
   tempo, parts, form variables, and source directory.
2. `processLily()` replaces the source tagline with the current date, deletes
   and recreates the song output directory, and schedules generation jobs.
3. `buildLayout()` creates a LilyPond `\book` and `\score` for the requested
   artifact, including transposition, clef, page layout, form overlay, and staff
   selection.
4. `createOutput()` writes temporary source under `/tmp`, invokes LilyPond, and
   returns the resulting PDF or MIDI bytes. Generated source is returned without
   invoking LilyPond.
5. `generateFile()` writes the artifact into the song directory and copies
   direct includes for generated source.
6. After all child processes finish, the song directory is zipped.

Up to 16 LilyPond jobs run concurrently. Each child adds its process ID to its
temporary filename to avoid collisions.

## Transposition and formatting

The key, clef, page, octave, MIDI-instrument, and percussion mappings are
hard-coded near the top of `pondscum.php`.

Pitched parts are wrapped in LilyPond `\transpose`. Pondscum adjusts the octave
according to the selected key, part, and clef, and applies an embedded
`naturalizeMusic` function to avoid spellings such as double sharps where
possible.

Lyre charts use B6 landscape paper, a smaller global staff size, compressed
vertical staff spacing, and LilyPond's one-page breaking strategy. Chord names
are suppressed on lyre charts.

## Single-output CLI

`pondscum_create.php` is a lower-level interface that produces one artifact:

```sh
php pondscum_create.php path/to/song.ly \
  part=melody key=Bb clef=treble page=letter \
  output=/tmp/melody.pdf
```

Recognized layout options include `part`, `key`, `clef`, `page`, `octave`,
`naturalize`, `words`, and `debug`. The `output` option supplies the destination
filename. This script has minimal argument validation; the batch generator is
generally safer and more convenient.

## Legacy web interface

`index.php` and `pondscum_web_index.php` implement a browser interface that:

- Lists LilyPond sources from a configured directory
- Offers part, key, clef, octave, and page-layout selectors
- Generates PDF, MIDI, or source output on demand
- Sends the generated bytes directly in the HTTP response

The source root is currently hard-coded in `index.php`:

```php
$workingdir = '/home/dameat/public_html/pondscum/blocharts/';
```

That is a deployment-specific server path. It is not used by the batch
generator, but it must be replaced or made configurable before the web
interface can run in another environment.

`generate_charts/output/indexes/generate_music_index.php` is a separate legacy
PHP view that scans an existing generated output tree and renders links to its
scores, parts, MIDI, source, lyrics, and ZIP files. It expects the caller to
define `$dir`.

## Current limitations and sharp edges

- Parsing is regex-based rather than LilyPond-aware; unconventional formatting
  can prevent metadata or variables from being detected.
- The batch generator deletes and recreates the complete output directory for
  each song.
- Direct include files are copied, but nested includes are not traversed.
- The current date is injected into the generated source tagline. Runs on
  different days therefore change the source, visible PDF footer, ZIP, and
  related artifact metadata even when the music is unchanged.
- LilyPond and Ghostscript also write fresh PDF timestamps and identifiers, so
  regenerated binary files are not byte-for-byte deterministic.
- The directory-removal helper uses a `*` glob, which does not match hidden
  files. A hidden file left in a song directory can prevent complete cleanup.
- Detection of `changes` and `words` predates the current grouped-parts data
  structure and should be verified before relying on chord-name or lyric output.
- The web interface contains deployment-specific assumptions and should be
  treated as legacy code.
- There is currently no automated test suite.

## Repository map

- `pondscum.php`: parsing, layout construction, LilyPond execution, transposition,
  form handling, percussion handling, and include copying
- `generate_charts/generate.php`: parallel batch generation and ZIP packaging
- `pondscum_create.php`: legacy single-output command-line wrapper
- `index.php`: legacy web entry point and source-directory configuration
- `pondscum_web_index.php`: web form and HTTP response helpers
- `generate_charts/output/indexes/generate_music_index.php`: legacy generated
  artifact index view
- `template.ly`: historical starter LilyPond source
- `notes.txt`: historical development notes

## License

This repository does not currently contain a license file. Do not assume reuse
terms beyond those granted by the repository owner.
