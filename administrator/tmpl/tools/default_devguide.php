<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/**
 * Tools → "Contributing" card: the step-by-step guide for turning the changes
 * made in this live install into a pull request. The package-export download is
 * step 3 of that flow (a zip of your changed files in the repo layout, for the
 * PR — not a general site export), so it lives inside the guide.
 *
 * Two tabs by tool (uitab HTMLHelper, which auto-loads the Bootstrap tab script):
 * "GitHub Desktop (easiest)" — extract the zip and the Changes tab shows
 * everything — and "Git commands". The outside-contributor (fork) path is a
 * collapsed <details> inside each tab, opened only if needed.
 *
 * The body is literal developer documentation; only the card chrome is translated.
 * Shared pieces (step-3 lead, library caution, CLI review/commit) are built once;
 * only the tool-specific deletions box and the per-tool steps differ.
 */

// CSRF-tokened export action (mirrors the other Tools cards' GET pattern).
$exportUrl = 'index.php?option=com_alfa&' . Session::getFormToken() . '=1&task=tools.exportPackage';

$downloadButton = '<a class="btn btn-primary mb-2" href="' . $this->escape($exportUrl) . '">'
    . '<span class="icon-download" aria-hidden="true"></span> '
    . Text::_('COM_ALFA_TOOLS_PACKAGE_BTN') . '</a>';

// Changed-only export: same task with &changed=1, so the server restricts the zip
// to the verified-changed files from the integrity baseline.
$changedButton = '<a class="btn btn-outline-primary mb-2 ms-2" href="' . $this->escape($exportUrl . '&changed=1') . '">'
    . '<span class="icon-download" aria-hidden="true"></span> '
    . Text::_('COM_ALFA_TOOLS_CHANGED_BTN') . '</a>';

// Concrete version example for the SQL-update / version-bump steps: the next
// version after this install's current one (PackageHelper::nextVersion via the
// view). Falls back to the generic placeholder when it can't be determined.
$nextVersion = $this->nextVersion !== '' ? $this->escape($this->nextVersion) : '&lt;new-version&gt;';

$structuralChanges = <<<HTML
<div class="alert alert-success small mb-2">
    <strong>First — structural changes.</strong> If your change adds or alters structure, do these <em>before</em> you download (the export only ships what's declared):
    <ul class="mb-0 mt-2">
        <li><strong>Added a plugin, module or library?</strong> Declare it in <code>alfa.xml</code> — under
            <code>&lt;plugins&gt;</code>, <code>&lt;modules&gt;</code> or <code>&lt;libraries&gt;</code> respectively.
            The export <em>only includes what's declared there</em>, so an undeclared plugin or module is
            <strong>silently left out of the zip and won't install</strong>.</li>
        <li><strong>Changed the database?</strong> Put your <code>ALTER</code> / <code>CREATE</code> statements in a new
            update file <code>sql/updates/mysql/&lt;new-version&gt;.sql</code> (e.g. <code>{$nextVersion}.sql</code>), and add
            the same statements to <code>sql/install.mysql.utf8.sql</code> so fresh installs get them. On
            update, Joomla runs every update file whose version is newer than the site's installed schema.
            <strong>Always use the <code>#__</code> table prefix</strong> — Joomla swaps in the site's real prefix when it
            runs the SQL — never the literal prefix from your own install (write <code>#__alfa_items</code>, not
            <code>jos_alfa_items</code>).</li>
        <li><strong>Deleted or moved a file?</strong> List the old paths in a new
            <code>files/removed/&lt;new-version&gt;.json</code> (e.g. <code>{$nextVersion}.json</code>) — shaped
            <code>{"files":[…],"folders":[…]}</code> with root-relative paths. On update the installer removes them,
            because Joomla never deletes files dropped between versions on its own. List only files you actually removed.</li>
        <li><strong>Bump the version.</strong> Set <code>&lt;version&gt;</code> in <code>alfa.xml</code> to the new number
            (match the file names above, e.g. <code>{$nextVersion}</code>) — that is what triggers the update SQL
            <em>and</em> the file cleanup to run.</li>
    </ul>
</div>
HTML;

$cautionDeletionsDesktop = <<<'HTML'
<div class="alert alert-danger py-2 small mb-2">
    <strong>⚠ Deletions &amp; renames:</strong> extracting the zip only <em>adds and overwrites</em> — it never
    removes anything. So if you deleted or renamed a file in your install, the old copy still sits in the repo.
    Just <strong>delete those files directly</strong> (in Finder / Explorer or your editor) — GitHub Desktop shows them
    in the <strong>Changes</strong> tab as deletions, ready to commit. Remove <em>only</em> what you took out on purpose;
    don't delete a file just because it isn't in your install — the repo keeps things your install doesn't have (e.g.
    other-language files like <code>el-GR</code>, since your install is en-GB), and those must stay.
</div>
HTML;

$cautionDeletionsCli = <<<'HTML'
<div class="alert alert-danger py-2 small mb-2">
    <strong>⚠ Deletions &amp; renames:</strong> extracting the zip only <em>adds and overwrites</em> — it never
    removes anything. So if you deleted or renamed a file in your install, the old copy still sits in the repo.
    Remove just those by hand:
<pre class="bg-light border rounded overflow-auto p-2 my-2 mb-1">git status                 # see what changed
git rm path/to/old-file    # ONLY for files you deleted or renamed yourself</pre>
    Remove <em>only</em> what you took out on purpose; don't delete a file just because it isn't in your install — the
    repo keeps things your install doesn't have (e.g. other-language files like <code>el-GR</code>, since your install
    is en-GB), and those must stay.
</div>
HTML;

// The library caution lists the declared libraries, so it is built dynamically.
ob_start(); ?>
<div class="alert alert-danger py-2 small mb-3">
    <strong>⚠ Libraries are not in the zip.</strong> The export <strong>skips everything under
    <code>libraries/</code></strong> (a library's <code>script.php</code> only exists at install time, so it can't be
    recovered from a live site). Two consequences:
    <ul class="mb-0 mt-1">
        <li>If you <strong>changed a library</strong> (added one, updated vendored code, edited a <code>lib_*</code> manifest),
            copy those files into the repo's <code>libraries/</code> by hand and commit them — otherwise they're dropped from your PR.</li>
        <?php if (!empty($this->libraries)) : ?>
        <li>To <strong>install or test the zip</strong>, add these library folders from the repo first:
            <ul class="mb-1 mt-1">
                <?php foreach ($this->libraries as $library) : ?>
                    <li><code>libraries/<?php echo $this->escape($library['folder']); ?>/</code>
                        <span class="text-muted">— <?php echo Text::sprintf(
                            'COM_ALFA_TOOLS_PACKAGE_LIBS_ITEM',
                            $this->escape($library['installCode']),
                            $this->escape($library['installManifest']),
                        ); ?></span></li>
                <?php endforeach; ?>
            </ul>
            Copy each <code>libraries/&lt;folder&gt;/</code> from the repo (it already holds the <code>lib_*.xml</code>
            manifest, <code>script.php</code> and <code>src/</code>) into the extracted package, then re-zip. Without them
            the component still installs, but the phone-number features that depend on these libraries will not work.
        </li>
        <?php endif; ?>
    </ul>
</div>
<?php
$cautionLibraries = ob_get_clean();

// Step 3 lead (download + what the zip is) — identical for both tools, built once.
// Each tab appends its tool-specific deletions box and the shared library box.
ob_start(); ?>
<h4 class="h6 mt-3">3. Get your changed files into the branch</h4>
<?php echo $structuralChanges; ?>

<?php /* ---------- Manifest⇄disk drift (pre-export sanity check) ---------- */ ?>
<?php if (!empty($this->drift['missing'])) : ?>
    <div class="alert alert-danger" role="alert">
        <strong><?php echo Text::_('COM_ALFA_TOOLS_DRIFT_MISSING_TITLE'); ?></strong>
        <p class="mb-2"><?php echo Text::_('COM_ALFA_TOOLS_DRIFT_MISSING_DESC'); ?></p>
        <ul class="mb-0">
            <?php foreach ($this->drift['missing'] as $item) : ?>
                <li><code><?php echo $this->escape($item); ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($this->drift['undeclared'])) : ?>
    <div class="alert alert-warning" role="alert">
        <strong><?php echo Text::_('COM_ALFA_TOOLS_DRIFT_UNDECLARED_TITLE'); ?></strong>
        <p class="mb-2"><?php echo Text::_('COM_ALFA_TOOLS_DRIFT_UNDECLARED_DESC'); ?></p>
        <ul class="mb-0">
            <?php foreach ($this->drift['undeclared'] as $item) : ?>
                <li><code><?php echo $this->escape($item); ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (empty($this->drift['missing']) && empty($this->drift['undeclared'])) : ?>
    <p class="small text-success mb-3">
        <span class="icon-checkmark-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_TOOLS_DRIFT_OK'); ?>
    </p>
<?php endif; ?>

<?php /* Release-artifact reminders for the current version — compact lines mirroring the drift-OK note; what each file does is explained in the structural-changes block above. Shown independently. */ ?>
<?php if (!empty($this->release['version']) && !$this->release['hasSqlUpdate']) : ?>
    <p class="small mb-2" style="color: #c2410c;">
        <span class="icon-warning" aria-hidden="true"></span>
        <?php echo Text::sprintf('COM_ALFA_TOOLS_RELEASE_SQL', $this->escape($this->release['version'])); ?>
    </p>
<?php endif; ?>

<?php if (!empty($this->release['version']) && !$this->release['hasRemovedJson']) : ?>
    <p class="small mb-2" style="color: #c2410c;">
        <span class="icon-warning" aria-hidden="true"></span>
        <?php echo Text::sprintf('COM_ALFA_TOOLS_RELEASE_REMOVED', $this->escape($this->release['version'])); ?>
    </p>
<?php endif; ?>

<?php /* Bump-awareness (official check): 'modified' = files differ but still on the
         released version number → bump it; 'ahead' = version already past the catalog. */ ?>
<?php if (($this->official['status'] ?? '') === 'modified') : ?>
    <p class="small mb-2" style="color: #c2410c;">
        <span class="icon-warning" aria-hidden="true"></span>
        <?php echo Text::sprintf('COM_ALFA_TOOLS_BUMP_REMINDER', $this->escape($this->official['officialVersion'] ?? '')); ?>
    </p>
<?php elseif (($this->official['status'] ?? '') === 'ahead') : ?>
    <p class="small text-success mb-2">
        <span class="icon-checkmark-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_TOOLS_BUMP_OK'); ?>
    </p>
<?php endif; ?>

<p class="small text-muted mb-2">
    <span class="icon-info-circle" aria-hidden="true"></span>
    <?php echo Text::_('COM_ALFA_TOOLS_CHANGELOG_REMINDER'); ?>
</p>

<?php echo $downloadButton; ?>
<?php
// Changed-only export — enabled when the official check reports real differences
// ('modified' = deviation from the matched version, 'ahead' = customised/off-catalog);
// otherwise a disabled button with the reason as a tooltip + a visible note.
$changedStatus = $this->official['status'] ?? '';
?>
<?php if ($changedStatus === 'modified' || $changedStatus === 'ahead') : ?>
    <?php echo $changedButton; ?>
<?php else : ?>
    <?php $changedWhy = $changedStatus === 'official' ? Text::_('COM_ALFA_TOOLS_CHANGED_NONE') : Text::_('COM_ALFA_TOOLS_CHANGED_UNVERIFIED'); ?>
    <?php /* pointer-events:auto overrides Bootstrap's .disabled (which would otherwise suppress both the not-allowed cursor and the tooltip). */ ?>
    <span class="btn btn-outline-primary mb-2 ms-2 disabled" aria-disabled="true" tabindex="-1"
          style="cursor: not-allowed; pointer-events: auto;"
          title="<?php echo $this->escape($changedWhy); ?>">
        <span class="icon-download" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_TOOLS_CHANGED_BTN'); ?>
    </span>
    <span class="small text-muted ms-2"><?php echo $changedWhy; ?></span>
<?php endif; ?>
<p class="small mb-2">
    This downloads <strong>the files in the repo layout</strong> — built for this pull request, <strong>not</strong>
    a site export or backup (no database data, only the schema / SQL files that ship with the code). Extract it
    <em>over</em> the repo folder.
</p>
<?php
$step3 = ob_get_clean();

// CLI review + commit — used by the Git-commands tab.
$cliReviewCommit = <<<'HTML'
<h4 class="h6 mt-3">4. Review what changed</h4>
<p class="small mb-1">This is your real "what changed" report — git, not a zip diff.</p>
<pre class="bg-light border rounded overflow-auto p-3 mb-3">git status
git diff</pre>

<h4 class="h6 mt-3">5. Commit</h4>
<p class="small mb-1">Keep the message short and in the imperative — start with "Add", "Fix", "Update", etc.</p>
<pre class="bg-light border rounded overflow-auto p-3 mb-3">git add -A
git commit -m "Add free-shipping threshold to cart"</pre>
HTML;
?>
<style>
/* Command blocks: wrap long lines so they never force the card wider than the viewport (Atum's flex layout otherwise grows to the longest line). */
.com-alfa-tools pre { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_PACKAGE_TITLE'); ?></div>
    <div class="card-body">
        <p class="text-muted mb-2"><?php echo Text::_('COM_ALFA_TOOLS_PACKAGE_DESC'); ?></p>
        <p class="mb-3">
            <a href="https://github.com/Easylogic-CO-LP/Alfa-Commerce" target="_blank" rel="noopener noreferrer">
                github.com/Easylogic-CO-LP/Alfa-Commerce
            </a>
        </p>

        <details class="border rounded p-3">
            <summary class="fw-bold" style="cursor:pointer;">
                <span class="icon-book" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_PACKAGE_TOGGLE'); ?>
            </summary>

            <div>

                <h4 class="h6">Branches — where things go</h4>
                <table class="table table-sm table-striped">
                    <thead>
                        <tr><th>Branch</th><th>Use</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>main</code></td><td>Stable releases. <strong>Never</strong> commit or PR directly.</td></tr>
                        <tr><td><code>developer</code></td><td>Active development. Base every branch here, and target it with PRs.</td></tr>
                        <tr><td><code>feat/*</code></td><td>New features, e.g. <code>feat/free-shipping-threshold</code>.</td></tr>
                        <tr><td><code>fix/*</code></td><td>Bug fixes, e.g. <code>fix/cart-total-rounding</code>.</td></tr>
                    </tbody>
                </table>

                <h4 class="h6 mt-3">Step by step — pick your tool</h4>
                <?php echo HTMLHelper::_('uitab.startTabSet', 'alfaPrGuide', ['active' => 'desktop']); ?>

                <?php /* ---------- GitHub Desktop ---------- */ ?>
                <?php echo HTMLHelper::_('uitab.addTab', 'alfaPrGuide', 'desktop', 'GitHub Desktop (easiest)'); ?>
                    <p class="small text-muted">No terminal. The neat part: after step 3 you extract the zip over the repo
                        folder and Desktop's <strong>Changes</strong> tab instantly shows everything that changed.</p>

                    <details class="border rounded p-2 mb-3 small">
                        <summary style="cursor:pointer;">Outside contributor (no push access)? — open for the fork steps</summary>
                        <p class="mb-0">
                            Fork the repo on GitHub, then in Desktop clone <em>your</em> fork instead of step 1 — when asked
                            <em>"How are you planning to use this fork?"</em>, choose <strong>To contribute to the parent
                            project</strong>. Follow steps 2–8 below unchanged; your pull request targets the parent's
                            <code>developer</code>.
                        </p>
                    </details>

                    <h4 class="h6">1. Clone the repo (one-time)</h4>
                    <p class="small mb-3"><strong>File → Clone repository</strong> → on the <strong>GitHub.com</strong> tab pick
                        <code>Easylogic-CO-LP/Alfa-Commerce</code> (or the <strong>URL</strong> tab and paste it), choose a local
                        folder, then click <strong>Clone</strong>.</p>

                    <h4 class="h6 mt-3">2. Branch off <code>developer</code></h4>
                    <p class="small mb-3">Click <strong>Current Branch</strong> (top bar) → select <code>developer</code>, then
                        <strong>Fetch origin</strong> for the latest. Click <strong>Current Branch → New Branch</strong>, name it
                        <code>feat/short-description</code>, set <strong>Create branch based on…</strong> to <code>developer</code>,
                        and click <strong>Create Branch</strong> → then <strong>Publish branch</strong>.</p>

                    <?php echo $step3; echo $cautionDeletionsDesktop; echo $cautionLibraries; ?>

                    <h4 class="h6 mt-3">4. Review what changed</h4>
                    <p class="small mb-3">The <strong>Changes</strong> tab lists every file — <span class="text-success">green = added</span>,
                        <span class="text-warning">yellow = modified</span>, <span class="text-danger">red = removed</span>. Click a file
                        to see its diff (the gear icon toggles Split / Unified).</p>

                    <h4 class="h6 mt-3">5. Commit</h4>
                    <p class="small mb-3">Bottom-left, fill the <strong>Summary</strong> (short, imperative — "Add…", "Fix…") and the
                        optional <strong>Description</strong>, then click <strong>Commit to feat/short-description</strong>.</p>

                    <h4 class="h6 mt-3">6. Catch up to <code>developer</code> before the PR</h4>
                    <p class="small mb-3">Click <strong>Current Branch → Choose a branch to merge into feat/short-description</strong>,
                        pick <code>developer</code>, then <strong>Merge developer into feat/short-description</strong>. Resolve any
                        conflicts now, not inside the PR.</p>

                    <h4 class="h6 mt-3">7. Push your branch</h4>
                    <p class="small mb-3">Click <strong>Push origin</strong> in the top bar.</p>

                    <h4 class="h6 mt-3">8. Open the pull request</h4>
                    <p class="small mb-1">Click <strong>Preview Pull Request</strong> (or the <strong>Branch</strong> menu →
                        <strong>Create Pull Request</strong>) and confirm <strong>base:</strong> is <code>developer</code> — never
                        <code>main</code>. Click <strong>Create Pull Request</strong>; Desktop opens GitHub, where you add:</p>
                    <ul class="small mb-0">
                        <li><strong>Title:</strong> concise summary, e.g. <em>"Add free-shipping threshold to cart"</em>.</li>
                        <li><strong>Description:</strong> what changed, why, and how to test it; link an issue with <code>Closes #123</code>.</li>
                    </ul>
                <?php echo HTMLHelper::_('uitab.endTab'); ?>

                <?php /* ---------- Git commands ---------- */ ?>
                <?php echo HTMLHelper::_('uitab.addTab', 'alfaPrGuide', 'cli', 'Git commands'); ?>
                    <p class="small text-muted">Prefer the terminal? Same flow, as commands.</p>

                    <details class="border rounded p-2 mb-3 small">
                        <summary style="cursor:pointer;">Outside contributor (no push access)? — open for the fork steps</summary>
                        <div>
                            Fork the repo on GitHub, then adjust three of the steps below:
                            <p class="mb-1 mt-2"><strong>Step 1</strong> — clone your fork and add the upstream remote:</p>
<pre class="bg-light border rounded overflow-auto p-2 mb-2">git clone https://github.com/YOUR-USERNAME/Alfa-Commerce.git
cd Alfa-Commerce
git remote add upstream https://github.com/Easylogic-CO-LP/Alfa-Commerce.git</pre>
                            <p class="mb-1"><strong>Step 2</strong> — branch off the upstream <code>developer</code>:</p>
<pre class="bg-light border rounded overflow-auto p-2 mb-2">git fetch upstream
git checkout -b feat/short-description upstream/developer</pre>
                            <p class="mb-1"><strong>Step 6</strong> — catch up from upstream:</p>
<pre class="bg-light border rounded overflow-auto p-2 mb-2">git fetch upstream
git merge upstream/developer</pre>
                            <p class="mb-0">Steps 3–5 and 7 stay the same (your push goes to your fork). In step 8, open the PR
                                <strong>from your fork into <code>Easylogic-CO-LP/Alfa-Commerce : developer</code></strong>.</p>
                        </div>
                    </details>

                    <h4 class="h6">1. Clone the repo (one-time)</h4>
<pre class="bg-light border rounded overflow-auto p-3 mb-3">git clone https://github.com/Easylogic-CO-LP/Alfa-Commerce.git
# or over SSH:  git clone git@github.com:Easylogic-CO-LP/Alfa-Commerce.git
cd Alfa-Commerce</pre>

                    <h4 class="h6 mt-3">2. Branch off <code>developer</code></h4>
<pre class="bg-light border rounded overflow-auto p-3 mb-3">git checkout developer
git pull                                     # latest developer
git checkout -b feat/short-description     # or fix/short-description</pre>

                    <?php echo $step3; echo $cautionDeletionsCli; echo $cautionLibraries; ?>

                    <?php echo $cliReviewCommit; ?>

                    <h4 class="h6 mt-3">6. Catch up to <code>developer</code> before the PR</h4>
                    <p class="small mb-1">Especially after a long-running branch — resolve any conflicts here, not inside the PR.</p>
<pre class="bg-light border rounded overflow-auto p-3 mb-3">git fetch origin            # get the latest developer (doesn't touch your files)
git merge origin/developer  # fold it into your branch</pre>

                    <h4 class="h6 mt-3">7. Push your branch</h4>
<pre class="bg-light border rounded overflow-auto p-3 mb-3">git push -u origin feat/short-description</pre>

                    <h4 class="h6 mt-3">8. Open the pull request</h4>
                    <p class="small mb-1">On GitHub, click <em>Compare &amp; pull request</em>, then set:</p>
                    <ul class="small mb-0">
                        <li><strong>Base / target:</strong> <code>developer</code> — switch it from <code>main</code> in the dropdown. Never target <code>main</code>.</li>
                        <li><strong>Title:</strong> concise summary, e.g. <em>"Add free-shipping threshold to cart"</em>.</li>
                        <li><strong>Description:</strong> what changed, why, and how to test it; link an issue with <code>Closes #123</code>.</li>
                    </ul>
                <?php echo HTMLHelper::_('uitab.endTab'); ?>

                <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

            </div>
        </details>
    </div>
</div>
