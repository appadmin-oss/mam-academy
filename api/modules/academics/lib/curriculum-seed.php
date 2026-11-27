<?php
/**
 * Starter curriculum — transcribed from MAM Academy's own programme documents
 * so the Standard Curriculum admin ships with a real, editable syllabus rather
 * than blank weeks. Educators load it once and refine it in the admin UI.
 *
 * Sources (provided by MAM):
 *   • "Grand Musicians II" (Certificate/Diploma, Trinity Grades 3–4) →
 *     the `cert` level for rhythm_pitch (RPT 201), piano (PNO 201),
 *     guitar (GTR 201), voice (VOC 201) and drums (DRM 223). Each module is a
 *     12-week outline across three pillars — African · Classical · Contemporary.
 *   • "Young Musicians · KTAMS" (Foundation, ages 4–12) Session 1 →
 *     the `precert` level for drums, guitar and piano (the Initial-Grade
 *     "First Steps" 12-week outline, one track per instrument).
 *
 * Shape: every syllabus is a full 12-week plan — weeks 1–10 modules, week 11
 * revision, week 12 examination — matching mam_lms_program_week_plan(). The
 * seeder (mam_lms_seed_starter_curriculum) imports these idempotently and
 * non-destructively. Content is condensed faithfully from the source tables.
 */
declare(strict_types=1);

/** The full starter curriculum: a list of { course, level, source, weeks[] }. */
function mam_lms_starter_curriculum(): array {
    $mod = fn(int $w, string $t, string $c = '') => ['week' => $w, 'kind' => 'module',   'title' => $t, 'content' => $c !== '' ? $c : null];
    $rev = fn(int $w, string $t, string $c = '') => ['week' => $w, 'kind' => 'revision', 'title' => $t, 'content' => $c !== '' ? $c : null];
    $exm = fn(int $w, string $t, string $c = '') => ['week' => $w, 'kind' => 'exam',     'title' => $t, 'content' => $c !== '' ? $c : null];

    return [
        // ─────────────────── Grand Musicians II → cert (Grades 3–4) ───────────────────
        ['course' => 'rhythm_pitch', 'level' => 'cert', 'source' => 'Grand Musicians II · RPT 201', 'weeks' => [
            $mod(1,  'Melodic minor play; spontaneous harmony', 'African: transcribe all parts of a classical song; Eyes & Ears rhythm drill; spontaneous harmony over partial-modulation progressions. Classical: melodic minor play; Exercises 16.1-17.1 & Eyes & Ears 93-97; 16-bar harmony writing. Contemporary: write the first 8 bars of a jazz/bebop melody; convert an exercise to a world-speciality groove.'),
            $mod(2,  'Melodic minor in 4ths; melody dictation', 'African: 16 bars of a jazz/bebop piece; rhythm drill; minor & dominant partial modulation. Classical: melodic minor ascending in 4ths; Ex 17.2-17.5 & Eyes & Ears 98-102; 16-bar melody dictation. Contemporary: sight-sing your assignment; harmony of 8 bars of jazz/bebop; Berklee 2 (37-41); Salsa groove.'),
            $mod(3,  'Melodic minor in 10ths; harmony writing', 'African: convert exercise to Kenyan grooves; rhythm drill; write 2 ethnic songs over all progressions. Classical: melodic minor ascending in 10ths; Ex 10.21-11.1 & Eyes & Ears 103-108; 16-bar harmony writing. Contemporary: sight-sing your assignment; melody of 8 bars of a Latin/Afro-Cuban piece.'),
            $mod(4,  'Blues play; melody dictation', 'African: convert exercise to Zulu grooves; 16 bars of Latin/Afro-Cuban; rhythm drill. Classical: blues play; Ex 18.1-19.1 & Eyes & Ears 109-115; 16-bar melody dictation. Contemporary: harmony of 8 bars of Latin/Afro-Cuban; Berklee 2 (42-47).'),
            $mod(5,  'Blues in 4ths; harmony writing', 'African: convert exercise to a Black Gospel song; 16 bars of gospel/blues; partial modulation. Classical: blues ascending in 4ths; Ex 19.2-19.6 & Eyes & Ears 116-120; harmony writing. Contemporary: melody of 8 bars of a gospel/blues piece.'),
            $mod(6,  'Blues in 10ths; melody dictation', 'African: write 2 ethnic songs in your tribe; rhythm drill. Classical: blues ascending in 10ths; Ex 19.7-20.5 & Eyes & Ears 121-125; melody dictation. Contemporary: harmony of 8 bars of gospel/blues; Berklee 2 (48-53).'),
            $mod(7,  'Pentatonic minor play; harmony writing', 'African: convert exercise to Kenyan grooves; all progressions with minor & dominant partial modulation. Classical: pentatonic minor play; Ex 20.6-21.18 & Eyes & Ears 126-130; harmony writing. Contemporary: melody of 8 bars of a Latin/Afro-Cuban piece.'),
            $mod(8,  'Pentatonic minor in 4ths; melody dictation', 'African: convert exercise to Asian grooves; 16 bars of Latin/Afro-Cuban. Classical: pentatonic minor ascending in 4ths; Ex 21.19-21.21; melody dictation. Contemporary: harmony of 8 bars of a Latin/Afro-Cuban piece; Berklee 2 (54-64).'),
            $mod(9,  'Pentatonic minor in 10ths; classical transcription', 'African: transcribe all parts of a classical song; rhythm drill. Classical: pentatonic minor ascending in 10ths; Ex 15.6-15.8; 16-bar classical harmony writing. Contemporary: spontaneous melody dictation, 16 bars of a classical piece.'),
            $mod(10, 'Full minor review; consolidation', 'African: write an ethnic song in your tribe; rhythm drill. Classical: full melodic & pentatonic minor review; 16-bar melody dictation. Contemporary: consolidate melody & harmony writing across all studied styles.'),
            $rev(11, 'Revision — grooves, scales, dictation & writing', 'African: Kenyan, Zulu, Asian & ethnic-tribe groove conversions. Classical: melodic minor, blues, pentatonic; sight-sing & dictation. Contemporary: jazz/bebop, Latin/Afro-Cuban, gospel/blues melody & harmony writing.'),
            $exm(12, 'Examination', 'Performance, Technical and Musicianship assessment across all three pillars.'),
        ]],
        ['course' => 'piano', 'level' => 'cert', 'source' => 'Grand Musicians II · PNO 201', 'weeks' => [
            $mod(1,  'Sight & Sound revision; G/F/Bb major scales', 'African: keyboard warm-up; revise Certificate-1 repertoire and inversions. Classical: revision on Sight & Sound; scales G, F, Bb major two-octave (tempo 80, hands separate); blocked C major chords (72). Contemporary: diagnostic — Sight & Sound revision; graded-piece readiness (ABRSM/RCM/Trinity).'),
            $mod(2,  'Grade 1-2 piece "Lullaby"; minor scales', 'African: carry graded technique into African groove keyboard study. Classical: Grade 1-2 piece "Lullaby"; E, D, G minor (harmonic & melodic) (80, HS); blocked G major chords (72). Contemporary: play Grade 1 piece "Lullaby"; graded classical performance.'),
            $mod(3,  'Nigeria Fake Book; broken D major', 'African: 200 Nigeria Fake Book — African accompaniment patterns. Classical: formula pattern; C, G major two octaves (80, hands together); broken D major chords (60, HS). Contemporary: "Soft, Soft, Music is Stealing" — lyrical piece.'),
            $mod(4,  'Grade 1-2 piece "Hunting Song"; chromatic', 'African: chromatic & broken-chord technique into African feel. Classical: Grade 1-2 piece "Hunting Song"; chromatic start; broken A minor (60, HS). Contemporary: play Grade 1 piece "Hunting Song".'),
            $mod(5,  'Legato pentascale & staccato; broken C major', 'African: 200 Nigeria Fake Book — "Three Blind Mice" African arrangement. Classical: legato pentascale, staccato (D, A) (60, HS); broken C major (50, HS). Contemporary: "Three Blind Mice" — articulation study.'),
            $mod(6,  'Grade 2-3 "Drink to Me Only..."; legato & staccato', 'African: graded articulation transferred into African keyboard idiom. Classical: Grade 2-3 piece "Drink to Me Only With Thine Eyes"; legato pentascale, staccato (F major, E & D minor, HS); broken G major one octave. Contemporary: play Grade 2 piece "Drink to Me Only With Thine Eyes".'),
            $mod(7,  'One-octave scales; broken A minor inversions', 'African: 200 Nigeria Fake Book — "Carnival Time" Highlife/Afro arrangement. Classical: one-octave scales C, G major & A minor; broken A minor inversions (HS). Contemporary: "Carnival Time" — festive piece.'),
            $mod(8,  'Grade 2-3 "Song of Happiness"; contrary motion', 'African: 200 Nigeria Fake Book — African accompaniment with solid root inversions. Classical: Grade 2-3 piece "Song of Happiness"; contrary-motion scale (C major, HT); solid root inversion C major, A minor (HS). Contemporary: play Grade 2 piece "Song of Happiness".'),
            $mod(9,  'Two-octave scales; solid root inversions', 'African: 200 Nigeria Fake Book — "Cradle Song" African arrangement. Classical: two-octave C, G major, A minor (natural & melodic, tempo 69, HS); solid root inversions G major, E minor. Contemporary: "Cradle Song" — lyrical performance.'),
            $mod(10, 'Grade 2-3 + "The Rose of Tralee"; broken inversions', 'African: transfer broken-inversion technique into African groove playing. Classical: Grade 2-3 piece + "The Rose of Tralee"; F major, E, D (natural & harmonic); broken inversion C major, A minor (50). Contemporary: "The Rose of Tralee"; play Grade 2 piece.'),
            $rev(11, 'Revision — Fake Book arrangements, scales & pieces', 'African: Nigeria Fake Book African arrangements. Classical: "Home on the Range" / "Waves of the Danube"; contrary-motion C major two octaves (HT); broken G major, E minor (100). Contemporary: revision — graded pieces, scales and inversions.'),
            $exm(12, 'Examination', 'Chromatic scale C major one octave; broken F major, D minor; graded performance.'),
        ]],
        ['course' => 'guitar', 'level' => 'cert', 'source' => 'Grand Musicians II · GTR 201', 'weeks' => [
            $mod(1,  'Diatonic & minor scales; clap-back ear test', 'African: convert a rhythm-book exercise into a song in your vocal range (African idiom). Classical: diatonic scale C, G, A one octave; minor (harmonic & melodic) E, F#, A one octave; ear-test clap-back Examples 1 & 2. Contemporary: diagnostic — Grade 3 readiness; aural & sight-singing baseline.'),
            $mod(2,  'Grade 3 "Minuet in C"; chromatic & tremolando', 'African: convert a rhythm exercise into a song in your vocal range. Classical: Grade 3 piece "Minuet in C"; chromatic A one octave; tremolando (C, G, A) one octave; intervals major 3rd & perfect 5th. Contemporary: play Grade 3 piece "Minuet in C".'),
            $mod(3,  'Grade 3 "Simple Gift"; tremolando minor', 'African: perform your new song; convert a rhythm exercise to African vocal idiom. Classical: Grade 3 piece "Simple Gift"; tremolando minor E, F#, A; diatonic major D, Bb, E, C; ear play-back (C, G, F). Contemporary: play "Simple Gift"; perform your new song.'),
            $mod(4,  'Grade 3 "Star-Spangled Banner"; diatonic minor', 'African: convert a rhythm exercise into a song. Classical: Grade 3 piece "The Star-Spangled Banner"; diatonic minor (H&M) B, C#, G; chromatic A two octaves; clap-back Ex 1 & 2. Contemporary: play "The Star-Spangled Banner".'),
            $mod(5,  'Grade 3 "Minuet in G"; tremolando & intervals', 'African: perform your new song; African phrasing of graded intervals. Classical: Grade 3 piece "Minuet in G"; tremolando D, Bb, E; tremolando minor B, C#, G and A two octaves; intervals 3rd, 4th, octave. Contemporary: play "Minuet in G"; perform your new song.'),
            $mod(6,  'Grade 4 "Morning Has Broken"; diatonic two-octave', 'African: convert a rhythm exercise into a song. Classical: Grade 4 piece "Morning Has Broken"; diatonic major Eb, Ab one octave, C, F# two octaves; ear play-back C, G, D, F. Contemporary: play Grade 4 piece "Morning Has Broken".'),
            $mod(7,  'Grade 4 "America the Beautiful"; sight-read & 3/4 clap', 'African: perform your new song; sight-reading & 3/4 clapping in African feel. Classical: Grade 4 piece "America the Beautiful"; diatonic minor D#, C, F one octave, A two octaves; sight-read G or D major; 3/4 clapping. Contemporary: play "America the Beautiful".'),
            $mod(8,  'Grade 3 "Scarborough Fair"; chromatic two-octave', 'African: convert a rhythm exercise into a song. Classical: Grade 3 piece "Scarborough Fair"; chromatic B two octaves; clap-back Ex 1 & 2. Contemporary: play "Scarborough Fair".'),
            $mod(9,  'Grade 3 "Greensleeves"; tremolando & intervals', 'African: perform your new song; African idiom on a modal melody. Classical: Grade 3 piece "Greensleeves"; tremolando Eb, Ab, C one octave, A, C two octaves; intervals major & minor 3rd, perfect 4th, 5th & octave. Contemporary: perform "Greensleeves"; play Grade 3 piece.'),
            $mod(10, '"Island Grove"; slur scale & clapping', 'African: convert a rhythm exercise into a song; 3/4 & 4/4 clapping. Classical: "Island Grove"; slur scale D major one octave; ear play-back C, G, D, F; clapping 3/4 & 4/4. Contemporary: "Island Grove" — performance piece.'),
            $rev(11, 'Revision — scales, graded pieces & new song', 'African: rhythm-to-song conversions; African phrasing. Classical: diatonic, chromatic, tremolando & slur scales; graded pieces. Contemporary: Grade 3 & 4 repertoire; perform your new song; sight-read G, D, A, F major.'),
            $exm(12, 'Examination', 'Technical work, graded pieces and performance across all three pillars.'),
        ]],
        ['course' => 'voice', 'level' => 'cert', 'source' => 'Grand Musicians II · VOC 201', 'weeks' => [
            $mod(1,  'Breath foundation; major scales; singing harmony', 'African: singing harmony in African idiom; breath foundation. Classical: breath (10s); revision; singing harmony; major scales; sight-sing major 2nd & 3rd. Contemporary: score a short classical solo in your range; establish graded readiness.'),
            $mod(2,  'Grade 3 piece; harmonic minor; major arpeggio', 'African: write a 16-bar major-scale voice score in your range (penta-scales, duo chords, I-IV-V-I). Classical: breath (12s); Grade 3 piece; harmonic minor play; singing harmony; major arpeggio; sight-sing minor 3rd. Contemporary: sing Grade 3 piece; harmonic-minor performance.'),
            $mod(3,  'Harmonic minor II; advanced breath; Rock & Funk', 'African: vocal imitation 1 in Rock & Funk; African articulation. Classical: breath (15s); harmonic minor play II; advanced breath management; harmonic minor scale; sight-sing perfect 4th. Contemporary: perform your new song; vocal imitation 1 in Rock & Funk style.'),
            $mod(4,  'Grade 3 triad sequence; minor arpeggio', 'African: write a 16-bar voice score (penta-scales, duo chords, i-iv-v-i). Classical: breath (17s); Grade 3 piece (harmonic ascending triad — 3rd, 4th, 6th); minor arpeggio; sight-sing perfect 5th. Contemporary: sing Grade 3 piece; triad-sequence performance.'),
            $mod(5,  'Harmonic ascending triad; chromatic; Shuffle & Mambo', 'African: vocal imitation 2 in Shuffle & Mambo; African groove phrasing. Classical: breath (20s); harmonic ascending triad; performance polish; chromatic scale; sight-sing major 6th. Contemporary: perform your new song; vocal imitation 2 in Shuffle & Mambo style.'),
            $mod(6,  'Grade 4 piece; major scale with turn', 'African: write a 16-bar voice score (i-iv-V-i). Classical: breath (22s); Grade 4 piece (harmonic ascending 10th); performance polish; major scale with turn; sight-sing perfect 8th. Contemporary: sing Grade 4 piece; performance with turns.'),
            $mod(7,  'Secondary 7th sequence; African 12/8 & Techno', 'African: vocal imitation 3 in African 12/8 & Techno; ethnic vocal inflection. Classical: breath (25s); harmonic minor secondary-7th sequence; flexibility/control/stamina; harmonic scale with turn. Contemporary: perform your new song; vocal imitation 3 in African 12/8 & Techno.'),
            $mod(8,  'Grade 4 triad sequence; melodic minor', 'African: write a 16-bar voice score (I-IV-V-I). Classical: breath (27s); Grade 4 piece (harmonic ascending triad sequence); flexibility/control/stamina; melodic minor scale. Contemporary: sing Grade 4 piece; triad-sequence performance.'),
            $mod(9,  'Melodic minor play; building blocks; Rhumba', 'African: vocal imitation 4 in Rhumba; African vocal stamina. Classical: breath (30s); melodic minor play; essential building blocks of vocal training; major 3rd exercise. Contemporary: perform your new song; vocal imitation 4 in Rhumba.'),
            $mod(10, 'Grade 5 piece; melodic minor II; chromatic', 'African: write a 16-bar voice score (mixed progression). Classical: breath (35s); Grade 5 piece; melodic minor play II; building blocks; chromatic scale. Contemporary: sing Grade 5 piece; advanced graded performance.'),
            $rev(11, 'Revision — vocal imitations, diction & new song', 'African: vocal imitations across all styles; ethnic-tribe voice scores. Classical: breath (40s); perform your new song; effective diction for singing. Contemporary: Grade 3-5 repertoire; score a short classical solo.'),
            $exm(12, 'Examination', 'Technical work, singing harmony, performance polish, diction, and vocal health & anatomy.'),
        ]],
        ['course' => 'drums', 'level' => 'cert', 'source' => 'Grand Musicians II · DRM 223', 'weeks' => [
            $mod(1,  'Pre-Certificate revision; stimuli & responses', 'African: revision baseline; African groove readiness. Classical: revise all Pre-Certificate strokes; play all stimuli & responses. Contemporary: diagnostic — revise strokes, stimuli & responses.'),
            $mod(2,  'Single/double/five-stroke roll; Hard Rock', 'African: Hard Rock groove in African feel; create a response (tempo 60-100). Classical: single/double/five-stroke roll (100-140); Stimuli 1 & Response 1 (Grade 3) — create & play. Contemporary: Hard Rock (100-140); create & play a response.'),
            $mod(3,  'Paradiddle, flam & flam tap; Funk', 'African: Funk with single-stroke roll (four, seven); African response to Stimuli 1. Classical: paradiddle (single/double/triple); flam & flam tap (60-100); Stimuli 2 & Response 2 (Grade 3). Contemporary: Funk; create & play a response to Stimuli 1.'),
            $mod(4,  'Multiple-bounce/drag & nine-stroke roll; Shuffle', 'African: Shuffle with double & five-stroke roll; response to Stimuli 1. Classical: multiple-bounce roll/drag; nine-stroke & double-stroke open roll (60-100); Stimuli 1-3 (Grade 3). Contemporary: Shuffle; create & play a response to Stimuli 1.'),
            $mod(5,  'Single/six/seven-stroke; African 12/8 paradiddle', 'African: African 12/8 with paradiddle roll; response to Stimuli 2 (the African core groove). Classical: single-stroke roll/six/seven; double & five-stroke roll (60-100); Stimuli 4 (Grade 3) in 3/4. Contemporary: African 12/8 with paradiddle roll; response to Stimuli 2 (100-140).'),
            $mod(6,  'Flam paradiddle; Techno', 'African: Techno with flam roll; African response to Stimuli 2. Classical: flam paradiddle (100-140); Stimuli 5 (Grade 3) in 3/4. Contemporary: Techno with flam roll; response to Stimuli 2 (100-140).'),
            $mod(7,  'Flam accent/flamacue/flam tap; Mambo', 'African: Mambo with drag roll; African response to Stimuli 2. Classical: flam accent/flamacue/flam tap/single drag tap (100-140); Stimuli 1 & Response 1 (Grade 4). Contemporary: Mambo with drag roll; response to Stimuli 2.'),
            $mod(8,  'Nine-stroke roll & single-stroke triplet; Rhumba', 'African: Rhumba with nine-stroke roll; African response to Stimuli 2. Classical: nine-stroke roll; single-stroke triplet (100); Stimuli 2 & Response 2 (Grade 4). Contemporary: Rhumba with nine-stroke roll; response to Stimuli 2.'),
            $mod(9,  'Double-stroke fill; Classical style', 'African: classicals with double-stroke fill; African response to Stimuli 2. Classical: revision of all strokes; Stimuli 1-3 (Grade 4); Response 2 (60-100). Contemporary: classicals with double-stroke fill; response to Stimuli 2.'),
            $mod(10, 'All fills; 6/8 grooves', 'African: all of the above fills; African response to Stimuli 2 in 6/8. Classical: all fills; Stimuli 4 & 5 (Grade 4) in 6/8 (100-140). Contemporary: all fills; response to Stimuli 2 (100-140).'),
            $rev(11, 'Revision — rudiments, stimuli & responses', 'African: African 12/8 and groove responses. Classical: finish-up & revision — all rudiments, stimuli and responses. Contemporary: all contemporary styles with signature rudiments.'),
            $exm(12, 'Examination', 'Rudiments, stimuli & created responses across all three pillars.'),
        ]],

        // ─────────── Young Musicians · KTAMS · Session 1 → precert (Initial Grade) ───────────
        ['course' => 'drums', 'level' => 'precert', 'source' => 'KTAMS Young Musicians · Session 1 (Initial Grade · First Steps)', 'weeks' => [
            $mod(1,  'Sitting at the kit; matched grip; steady pulse', 'Session 1 · Initial Grade — First Steps. Featured repertoire — Classical: "Ode to Joy" (Beethoven); Contemporary: "You\'ve Got a Friend in Me" (Randy Newman, Toy Story).'),
            $mod(2,  'Quarter-note pulse on the bass drum; counting 1-2-3-4'),
            $mod(3,  'Naming the kit voices — snare, bass, hi-hat; play each on cue'),
            $mod(4,  'Loud and soft strokes on the snare; stick control'),
            $mod(5,  'Quarter notes vs half notes; saying "ta" and "ta-a"'),
            $mod(6,  'A three-stroke pattern (snare-snare-bass), repeated'),
            $mod(7,  'First beat — bass on 1 & 3, snare on 2 & 4'),
            $mod(8,  'Keeping time while the teacher plays the tune'),
            $mod(9,  'Reading a four-beat rhythm grid'),
            $mod(10, 'Learning the beat for the two featured songs'),
            $rev(11, 'Revision — full-band rehearsal'),
            $exm(12, 'Initial Assessment', 'Performance, Technical and Musicianship.'),
        ]],
        ['course' => 'guitar', 'level' => 'precert', 'source' => 'KTAMS Young Musicians · Session 1 (Initial Grade · First Steps)', 'weeks' => [
            $mod(1,  'Holding the guitar; naming the six open strings; one steady downstrum', 'Session 1 · Initial Grade — First Steps. Featured repertoire — Classical: "Ode to Joy" (Beethoven); Contemporary: "You\'ve Got a Friend in Me" (Randy Newman, Toy Story).'),
            $mod(2,  'One downstrum per beat on open E; counting 1-2-3-4'),
            $mod(3,  'Open-string names (E A D G B E); pluck each in turn'),
            $mod(4,  'Strumming loud and soft; pick control'),
            $mod(5,  'Long ringing notes vs short muted strums'),
            $mod(6,  'A three-note tune on the top string'),
            $mod(7,  'Four steady downstrums per bar'),
            $mod(8,  'Strumming a steady beat while the class sings'),
            $mod(9,  'Reading a simple strum chart'),
            $mod(10, 'Learning the strum for the two featured songs'),
            $rev(11, 'Revision — full-band rehearsal'),
            $exm(12, 'Initial Assessment', 'Performance, Technical and Musicianship.'),
        ]],
        ['course' => 'piano', 'level' => 'precert', 'source' => 'KTAMS Young Musicians · Session 1 (Initial Grade · First Steps)', 'weeks' => [
            $mod(1,  'Sitting tall; curved fingers; finger numbers 1-5; steady pulse', 'Session 1 · Initial Grade — First Steps. Featured repertoire — Classical: "Ode to Joy" (Beethoven); Contemporary: "You\'ve Got a Friend in Me" (Randy Newman, Toy Story).'),
            $mod(2,  'Middle C on every beat; counting 1-2-3-4'),
            $mod(3,  'Finding Middle C; the white-key names C-D-E'),
            $mod(4,  'Playing forte and piano on C-D-E'),
            $mod(5,  'Crotchets and minims with one finger'),
            $mod(6,  'Playing C-D-E by ear and by sight'),
            $mod(7,  'Four steady beats per bar with both hands'),
            $mod(8,  'Pupil melody with teacher accompaniment'),
            $mod(9,  'Reading a two-bar phrase from the stave'),
            $mod(10, 'Learning the melody for the two featured songs'),
            $rev(11, 'Revision — full-band rehearsal'),
            $exm(12, 'Initial Assessment', 'Performance, Technical and Musicianship.'),
        ]],
    ];
}
