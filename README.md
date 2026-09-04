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

## Batch compatibility contract

This section describes the observable input-to-output behavior that a Pondscum
replacement should cover. It distinguishes the fixed batch product from the
larger, legacy on-demand interface described later.

### Input-to-output map

| Source input or convention | Transformation | Observable output |
| --- | --- | --- |
| One or more existing paths ending in `.ly` | Each file is parsed and generated independently. Inputs are sorted by detected title before generation. | One song directory and ZIP per accepted source. |
| Quoted LilyPond `title` | Spaces are replaced with underscores; all other title characters are retained. | The normalized title names the song directory and every generated artifact. |
| `%description: ...` on one line | The text after the colon is copied verbatim; it is not interpreted or escaped. | Optional `<song>/description.txt`. Existing descriptions may contain HTML. |
| `%part: name` plus a nonempty matching variable | The marker is discovered with a case-insensitive regex. The variable name itself is used in generated LilyPond. | A logical part group, a part directory, and part artifacts as classified below. |
| Multiple names with the same prefix before `_`, such as `melody_call` and `melody_response` | They become separate staffs in one logical `melody` part. Text after the first underscore becomes the staff label. | One `melody/` matrix; every PDF in it contains both staffs. |
| A detected `form` variable | Overlaid only on the first generated staff. | Shared marks, repeats, breaks, and other skip-based form content in the score, MIDI, source, and extracted parts. |
| A detected `lyreForm` variable | Used instead of `form` for lyre output, but only if `form` also exists. | A compact-form overlay in lyre PDFs. |
| A detected `<variableName>Lyre` variable | Replaces that staff's ordinary variable in lyre output. | Different musical content in lyre PDFs; letter PDFs, score, MIDI, and generated source continue to use the ordinary part. |
| First line containing `tempo...` | The text after `tempo` is inserted as a `\\tempo` command on every generated staff; absence defaults to `4 = 100`. | Tempo marks in PDFs and tempo information in MIDI. |
| Direct relative `\\include "path"` | The source directory is added to LilyPond's include path. Safe, directly referenced files are copied with their relative path. | Includes work during engraving and appear under `<song>/source/`. Nested, absolute, and parent-traversing includes are not copied. |
| Existing `%layout`, `%%layout`, `%Generated layout`, or `%%Generated layout` boundary | The boundary and everything after it are discarded before a new layout is appended. | Old generated layout code does not accumulate. |
| Existing indented `tagline = ...` in the header | Removed, then replaced with the generation date in `m/d/Y` form. | The generated source and rendered PDFs change with the date. |

An exact empty definition of the form `name = { }`, with any non-brace setup
between `=` and `{` (for example `\\markup`), is discarded. This is a regex
heuristic, not a semantic emptiness check.

### Artifact classes

Let:

- `P` be the number of ordinary, non-percussion part groups;
- `R` be the number of recognized percussion groups;
- `I` be the number of safe direct includes copied; and
- `D` be 1 when a description is present, otherwise 0.

Ignoring the legacy `words`, `lyrics`, and `changes` cases described below, a
successful batch run produces `4 + 10P + R + I + D` files on disk: three core
artifacts, ten PDFs per ordinary part, one PDF per percussion part, copied
includes, an optional description, and one ZIP.

| Artifact class | Count | Musical content and transformation | Path pattern |
| --- | ---: | --- | --- |
| Full score PDF | 1 | All discovered staffs except the special `changes` and `words` groups, at authored pitches. Exact group `bass` uses bass clef; recognized percussion uses `DrumStaff`; all other groups use treble clef. `form` is applied to the first staff only. | `<song>/score/<song>-score.pdf` |
| MIDI | 1 | The same score staffs at authored pitches, with repeats unfolded, no chord names or lyrics, and mapped MIDI instruments. A LilyPond layout block is still present alongside `\\midi`. | `<song>/midi/<song>.mid` |
| Generated LilyPond | 1 | The date-modified source plus a generated full-score layout. Pondscum returns text directly instead of invoking LilyPond for this artifact. | `<song>/source/<song>.ly` |
| Copied include | 0..N | Each safe include referenced directly by the main source, byte-for-byte. | `<song>/source/<relative-include-path>` |
| Description | 0 or 1 | Verbatim one-line description text. | `<song>/description.txt` |
| Ordinary part PDFs | 10 per group | One extracted-part PDF for every row and page variant in the matrix below. Grouped subparts become multiple staffs. | `<song>/<part>/<song>-<part>-<key>-<clef>_clef-<page>.pdf` |
| Percussion PDF | 1 per group | No transposition or accidental naturalization; rendered as `DrumStaff` on letter paper. Recognized names are `bassDrum`, `snareDrum`, and `cowbell`. | `<song>/<part>/<song>-<part>-C-percussion_clef-letter.pdf` |
| ZIP | 1 | Recursively contains the top-level song directory and its artifacts, but not the ZIP itself. `.DS_Store` files are removed first. | `<song>/<song>.zip` |

The normalized `<song>` value replaces only literal spaces in the title with
underscores. For example, `J.J.D.` becomes the directory `J.J.D.` and yields a
ZIP named `J.J.D..zip` (the title's final period plus the extension separator).

### Ordinary-part PDF matrix

Every ordinary part gets exactly these ten batch PDFs:

| Written-key label | LilyPond transposition with default octave | Clef | Letter | Lyre |
| --- | --- | --- | --- | --- |
| C | `\\transpose c c` | Treble | Yes | Yes |
| C | `\\transpose c c,` | Bass | Yes | Yes |
| B-flat | `\\transpose bes c'` | Treble | Yes | Yes |
| E-flat | `\\transpose ees c'` | Treble | Yes | Yes |
| F | `\\transpose f c'` | Treble | Yes | Yes |

There are two exact-name octave exceptions:

- A part whose logical name is exactly `bass` is raised one additional octave
  in treble clef: C uses `\\transpose c c'`; B-flat, E-flat, and F use a `c''`
  destination.
- Any non-`bass` part in C bass clef uses the `c,` destination shown above.
  Exact `bass` in C bass clef instead uses `\\transpose c c`.

The batch generator does not emit B-flat, E-flat, or F bass-clef parts. Although
`alto` and `tenor` are present in the internal clef list, it emits neither clef.
The order in which files finish is nondeterministic because up to 16 LilyPond
jobs run concurrently; the set and names of artifacts are the contract.

For both page variants, accidental naturalization is enabled by default. Letter
parts use US Letter paper and may include chord names when that feature works.
Lyre parts use B6 landscape paper, staff size 15, compressed vertical spacing,
one-page breaking, no chord names, `lyreForm` when available, and each staff's
`<variableName>Lyre` music when available. Despite the compact intent, the two C
bass-clef variants are generated for lyre as well as letter output.

### MIDI instrument and score-staff mapping

The logical group name selects a MIDI instrument in full score, source, and
MIDI generation. It does not change the extracted part's visible notation.

| Logical group | MIDI instrument | Default score clef/staff |
| --- | --- | --- |
| `bass` | tuba | Bass `Staff` |
| `melody` | trumpet | Treble `Staff` |
| `tenor`, `pahs`, `chordLo` | trombone | Treble `Staff` |
| `riffTwo`, `harmony` | clarinet | Treble `Staff` |
| `chordMid`, `bari` | baritone sax | Treble `Staff` |
| `countermelody` | alto sax | Treble `Staff` |
| Any other ordinary group | alto sax | Treble `Staff` |
| `bassDrum`, `snareDrum`, `cowbell` | LilyPond drum pitches, not a named MIDI instrument | Percussion `DrumStaff` |

### Typical tree

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
      Example_Tune-melody-C-bass_clef-letter.pdf
      Example_Tune-melody-C-bass_clef-lyre.pdf
      Example_Tune-melody-Bb-treble_clef-letter.pdf
      Example_Tune-melody-Bb-treble_clef-lyre.pdf
      Example_Tune-melody-Eb-treble_clef-letter.pdf
      Example_Tune-melody-Eb-treble_clef-lyre.pdf
      Example_Tune-melody-F-treble_clef-letter.pdf
      Example_Tune-melody-F-treble_clef-lyre.pdf
    bassDrum/
      Example_Tune-bassDrum-C-percussion_clef-letter.pdf
```

### Legacy special groups: compatibility decisions required

`changes`, `words`, and `lyrics` are not currently a clean supported matrix:

- `changes` is omitted from full-score staffs, but the batch loop treats a
  nonempty `changes` variable as an ordinary part and attempts ten standalone
  PDFs under `changes/`.
- `words` is omitted from full-score staffs and gets one standalone PDF job,
  with unset key and clef fields in a filename such as
  `<song>-words--_clef-letter.pdf`.
- `lyrics` also gets one standalone PDF job, but unlike `words` it is not
  excluded from the full score. Its standalone filename follows the same
  unset-field pattern.
- Detection that should add `changes` as chord names and `words` as text still
  uses the pre-grouping data shape. In the present code it evaluates false even
  when those groups were discovered.
- A failed LilyPond job can still leave an empty output file because child-job
  failures are not propagated to the parent batch process.

These behaviors should be captured in characterization tests before removal,
then classified explicitly as either compatibility requirements or bugs to fix.
A replacement should never use file existence alone as a success assertion: it
should require every PDF and MIDI artifact to be nonempty and parseable.

### Replacement acceptance checklist

For a representative fixture containing an ordinary part, grouped subparts,
exact `bass`, all three percussion names, `form`, `lyreForm`, `<part>Lyre`, a
description, tempo, and a direct include, assert that the replacement:

1. Produces the exact relative path set predicted above, including all ten
   ordinary-part variants and one PDF per percussion group.
2. Produces a nonempty, readable full-score PDF, every part PDF, and MIDI file;
   engraves the generated source successfully with its copied includes.
3. Preserves staff membership and ordering, grouped-part labels, clefs, written
   pitches/octaves, form marks, repeats, tempo, and lyre-specific content.
4. Preserves filename normalization, the optional description, and ZIP member
   paths; compares ZIP members rather than ZIP bytes.
5. Tests the `changes`, `words`, and `lyrics` cases separately and records which
   legacy quirks are intentionally retained or corrected.
6. Uses semantic comparisons where output is nondeterministic: PDF page count
   and extracted/rendered content, MIDI tracks/events, generated LilyPond text
   with the date normalized, and ZIP member names/content with timestamps
   ignored.

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

Unlike batch generation, this wrapper does not constrain requests to the fixed
matrix. A caller can request any combination accepted by `buildLayout()`:

| Dimension | Values exposed by Pondscum |
| --- | --- |
| Part | Any discovered logical part, plus `score`, `midi`, or `source` |
| Key | `Eb`, `Bb`, `C`, `F` |
| Clef | `treble`, `bass`, `alto`, `tenor` |
| Page | `letter`, `lyre` |
| Octave adjustment | `+2`, `+1`, `0`, `-1`, `-2` |
| Naturalize accidentals | On or off |
| Include words | On or off |
| Debug response | On or off |

These dimensions describe reachable on-demand behavior, not a promise that
every Cartesian-product combination engraves successfully. The batch matrix is
the compatibility target for files published by the current blocharts build;
replacing the old CLI or web service as well requires characterization tests for
the extra clefs, octaves, toggles, and combinations.

## Legacy web interface

`index.php` and `pondscum_web_index.php` implement a browser interface that:

- Lists LilyPond sources from a configured directory
- Offers part, key, clef, octave, and page-layout selectors
- Generates PDF, MIDI, or source output on demand
- Sends the generated bytes directly in the HTTP response

The form exposes the same key, clef, page, octave, naturalization, and words
dimensions listed above. PDF responses are displayed inline; MIDI and generated
source are sent as downloads. A `debug` request returns the generated LilyPond
and LilyPond console output as HTML instead of an artifact.

The source root is currently hard-coded in `index.php`:

```php
$workingdir = '/home/dameat/public_html/pondscum/blocharts/';
```

That is a deployment-specific server path. It is not used by the batch
generator, but it must be replaced or made configurable before the web
interface can run in another environment.

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
- Detection of `changes` and `words` predates the current grouped-parts data
  structure and currently fails even when those groups are discovered.
- Failed child jobs are not propagated to the batch process, and an empty
  artifact can be left behind. Consumers must validate output content.
- The web form's part selector also predates the grouped-parts data structure
  and does not currently enumerate logical part names correctly. Directly
  constructed requests can still reach the underlying generator.
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
- `template.ly`: historical starter LilyPond source
- `notes.txt`: historical development notes

## License

This repository does not currently contain a license file. Do not assume reuse
terms beyond those granted by the repository owner.
