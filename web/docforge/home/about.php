<?php
require_once __DIR__ . '/includes/bootstrap-page.php';

$pageTitle = 'About';
$activeNav = 'about';

require __DIR__ . '/includes/header.php';
?>

<main class="df-about" id="view-about">
  <header class="df-about-hero">
    <img class="df-about-logo" src="<?php echo $assetBase; ?>images/docforge_logo.png"
         alt="DocForge">
    <p class="df-about-tag">Understand documents. Don&rsquo;t just parse them.</p>
  </header>

  <section class="df-about-lead">
    <p>
      DocForge is a document intelligence engine. It transforms any document &mdash; a
      500-page PDF, a scanned contract, a Word form, a source file &mdash; into structured,
      trusted knowledge: what the document says, how it is organised, what it cites, who and
      what it mentions, what its tables contain, and how much of that extraction can be
      trusted. Every document is reduced to a <strong>Knowledge Layer</strong>, and every
      output &mdash; a Markdown report for people, a JSON export for programs, a searchable
      library record &mdash; is a projection of that one model. The product is the knowledge,
      not the conversion.
    </p>
  </section>

  <section class="df-about-section">
    <h2>The No-LLM paradigm</h2>
    <p>
      DocForge practises <strong>No-LLM document intelligence</strong>: deriving structured
      knowledge through deterministic analysis rather than probabilistic language generation.
      The name echoes NoSQL &mdash; &ldquo;Not Only LLM&rdquo; &mdash; a complement, not a
      rejection. Large language models excel at understanding, summarising and generating
      language; DocForge addresses the different class of problem where the governing question
      is not <em>&ldquo;what might this mean?&rdquo;</em> but
      <em>&ldquo;can I trust exactly where this information came from?&rdquo;</em>
    </p>
    <p>
      No generative model is called at any point. Runs are deterministic (same input, same
      output &mdash; provably, via the fingerprint), extractive (nothing in an output that is
      not in the source), attributed (every element linked to its source location), measured
      (every result carries a confidence), local (works with the network unplugged), and free
      at the margin (CPU once, on owned hardware).
    </p>
  </section>

  <section class="df-about-section">
    <h2>Design principles</h2>
    <ol class="df-about-list">
      <li><strong>Extract, never hallucinate.</strong> Nothing in an output that is not in the source.</li>
      <li><strong>Every claim is attributable.</strong> Source location and method, always.</li>
      <li><strong>Every result has confidence.</strong> Measured where possible, honest heuristic where not.</li>
      <li><strong>Human-readable by default, machine-readable by design.</strong> Markdown for people; JSON from the same layer for programs.</li>
      <li><strong>Local-first.</strong> Works with the network unplugged; nothing leaves the server.</li>
      <li><strong>Modular and replaceable.</strong> Capabilities are contracts; components are candidates behind them.</li>
      <li><strong>Nothing degrades silently.</strong> Failure and degradation are report content, not log noise.</li>
    </ol>
  </section>

  <section class="df-about-section">
    <h2>Core capabilities</h2>
    <div class="df-about-grid">
      <div class="df-about-card"><h3>Universal ingestion</h3><p>Any file in, through one plugin contract.</p></div>
      <div class="df-about-card"><h3>Structural understanding</h3><p>Layout, headings, sections and pages.</p></div>
      <div class="df-about-card"><h3>Semantic analysis</h3><p>Summaries, key findings, keyphrases and entities.</p></div>
      <div class="df-about-card"><h3>Evidence extraction</h3><p>References, tables, statistics and image content &mdash; verbatim and located.</p></div>
      <div class="df-about-card"><h3>Trust &amp; provenance</h3><p>Confidence, provenance, quality assessment, Knowledge Score and fingerprint.</p></div>
      <div class="df-about-card"><h3>Publishing</h3><p>Normative Markdown report, JSON export and downloads.</p></div>
    </div>
  </section>

  <section class="df-about-section">
    <h2>The trust layer is the product</h2>
    <p>
      A converter answers <em>&ldquo;what does this document say?&rdquo;</em> DocForge answers
      that, plus <em>&ldquo;how much of this answer should you believe, where did each piece
      come from, and have I seen this document before?&rdquo;</em> Those three questions are
      precisely what a downstream reader &mdash; human or machine &mdash; cannot generate for
      itself.
    </p>
    <ul class="df-about-list">
      <li><strong>Fingerprint &amp; deduplication.</strong> A SHA-256 heads every report, making runs reproducible and duplicates detectable (&ldquo;previously processed as report&nbsp;#N&rdquo;).</li>
      <li><strong>Confidence scoring.</strong> Every dimension emits 0&ndash;100 from measurable signals; dimensions not analysed report <em>n/a</em> and drop out of the composite rather than inflating it.</li>
      <li><strong>Quality Verdict.</strong> An active post-analysis pass with content-loss invariants &mdash; degradation becomes report content, never silent noise.</li>
      <li><strong>Knowledge Score.</strong> One headline number with sub-scores and stars; a floor-level failure in a critical dimension caps the whole score, so a beautiful skeleton with no body never scores highly.</li>
      <li><strong>Provenance.</strong> Every section carries its source location, method (with fallbacks explicit) and confidence, so a report never looks better-founded than it is.</li>
    </ul>
  </section>

  <section class="df-about-section">
    <h2>One model, three projections</h2>
    <p>
      Parsing and analysis are coupled only through an Intermediate Representation, and the
      trust layer sits between analysis and output &mdash; so nothing reaches an export
      without confidence and provenance attached. Every parser populates the same
      <strong>Knowledge Layer</strong>, which is then rendered as a normative 14-section
      Markdown report, serialised as schema-validated JSON, and persisted as a queryable
      library record. The reports carry roughly 95&ndash;98% of what a reader needs to work
      without opening the original for text-dominant documents &mdash; the losses are the
      fixes: noise removed, with a receipt.
    </p>
  </section>

  <footer class="df-about-origin">
    <p>
      Built by <strong>Brian O&rsquo;Regan</strong>, Senior Research Engineer, Energy
      Informatics Group, International Energy Research Centre (IERC), Tyndall National
      Institute. Version&nbsp;1 &mdash; <code>phase-1-baseline</code>.
    </p>
    <p class="df-about-cta">
      <a class="btn btn-forge" href="index.php">Forge a document</a>
    </p>
  </footer>
</main>

<?php
require __DIR__ . '/includes/footer.php';
