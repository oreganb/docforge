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
      DocForge reads your documents so you don&rsquo;t have to. Drop in a file &mdash; a PDF,
      a Word document, a scanned report, a plain text file &mdash; and it produces one clean,
      readable report telling you what&rsquo;s inside: a summary, the structure, the key
      points, the tables, the references, and the full text. Every report is saved to your
      library, where you can search, view, and download it any time.
    </p>
  </section>

  <section class="df-about-section">
    <h2>How it works</h2>
    <p>
      Every document goes through six steps, and you watch them happen on the progress bar:
      <strong>Read</strong> the file, <strong>Understand</strong> its layout,
      <strong>Analyse</strong> the content, <strong>Verify</strong> the quality,
      <strong>Build</strong> the report, <strong>Publish</strong> it to your library.
      It usually takes seconds.
    </p>
  </section>

  <section class="df-about-section">
    <h2>What you get</h2>
    <div class="df-about-grid">
      <div class="df-about-card"><h3>Any file in</h3><p>PDF, Word, Markdown, text &mdash; with spreadsheets, images and more on the way.</p></div>
      <div class="df-about-card"><h3>A clear summary</h3><p>The document&rsquo;s main points, taken word-for-word from the text itself.</p></div>
      <div class="df-about-card"><h3>The structure</h3><p>Every section and heading, mapped out so you can jump to what matters.</p></div>
      <div class="df-about-card"><h3>The evidence</h3><p>Tables, references, key figures and statistics &mdash; exactly as they appear in the source.</p></div>
      <div class="df-about-card"><h3>A quality score</h3><p>One number telling you how well the extraction went &mdash; and a plain list of anything that didn&rsquo;t.</p></div>
      <div class="df-about-card"><h3>Two formats</h3><p>A readable report for you, and a structured data file for your tools.</p></div>
    </div>
  </section>

  <section class="df-about-section">
    <h2>Can I trust the result?</h2>
    <p>
      That&rsquo;s the whole point. Every report starts with a <strong>Knowledge
      Score</strong> &mdash; one number that tells you how good the extraction was. If
      anything went wrong &mdash; unreadable pages, a table that didn&rsquo;t survive,
      low-quality text in the source &mdash; the report says so plainly, right at the top.
      DocForge never hides a problem, and it never invents content: every sentence in a
      report exists, word for word, in your original document.
    </p>
  </section>

  <section class="df-about-section">
    <h2>No AI writing &mdash; on purpose</h2>
    <p>
      DocForge doesn&rsquo;t use ChatGPT-style AI. Nothing is generated, guessed, or made up,
      so nothing can be hallucinated. The same file always produces the same report, every
      part of a report can be traced back to its place in the source, and your documents
      never leave the server. We call this approach <strong>No-LLM</strong> &mdash; and,
      usefully, it makes DocForge reports the perfect lightweight, trustworthy version of
      your documents to hand to AI tools when you <em>do</em> want to use one.
    </p>
  </section>

  <section class="df-about-section">
    <h2>Your files</h2>
    <p>
      Original files are deleted the moment processing finishes. Only the report is kept, in
      your library. If a document matters in its original form, keep the original where you
      normally would &mdash; the report is your working copy, not your archive.
    </p>
  </section>

  <section class="df-about-section">
    <p>
      <strong>Most tools convert documents. DocForge tells you what&rsquo;s in them &mdash;
      and how much to trust what it found.</strong>
    </p>
  </section>

  <footer class="df-about-origin">
    <p class="df-about-cta">
      <a class="btn btn-forge" href="index.php">Forge a document</a>
    </p>
  </footer>
</main>

<?php
require __DIR__ . '/includes/footer.php';
