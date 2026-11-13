#!/usr/bin/env bash
# Tests for the php-security job in .github/workflows/security-scan.yml.
#
# Run from anywhere: bash tests/ci/php-security-test.sh
# Needs bash and ruby (its standard library reads the YAML). Exits non-zero when any check fails.
#
# Part 1 reads the workflow file and checks the job's shape: it runs a named scanner pinned to an exact
#        version and image digest, reads rules fetched at an exact commit, fails on any finding, reads the
#        folders Alfa Commerce ships, has no path that skips the scan, and pins every action by commit.
# Part 2 runs only when SEMGREP_BIN names a Semgrep of the job's version and the rules have been
#        fetched into .semgrep-rules at the job's commit (the job's own checkout step, done by hand):
#        the job's command, with SEMGREP_BIN in place of the image, must pass on this checkout and must
#        fail on a copy of it that echoes a request value.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/security-scan.yml"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
failures=0

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; failures=$((failures + 1)); }

# Part 1. Also writes the scan command's arguments, one per line, to $WORK/args for part 2.
ruby --disable-gems - "$WORKFLOW" "$WORK/args" > "$WORK/ruby.out" 2>&1 <<'RUBY'
require "yaml"

workflow, args_out = ARGV
results = []
check = lambda { |ok, label| results << [ok, label] }

job = (YAML.load_file(workflow)["jobs"] || {})["php-security"]
check.call(job.is_a?(Hash), "the workflow has a php-security job")
job = {} unless job.is_a?(Hash)
steps = job["steps"] || []
text = steps.map { |s| [s["run"], (s["with"] || {}).values].flatten.compact.join("\n") }.join("\n")

check.call(job["continue-on-error"].nil? && steps.none? { |s| s.key?("continue-on-error") },
           "no continue-on-error on the job or a step")
check.call(text !~ /composer\.lock|enlightn|security-checker|skipping/i,
           "no step reads a composer.lock or prints a skip: the scan always runs")
check.call(text !~ /\|\|\s*true|;\s*true\b|exit 0/, "no step swallows the scanner's exit code")

uses = steps.map { |s| s["uses"] }.compact
check.call(!uses.empty? && uses.all? { |u| u =~ /@[0-9a-f]{40}\z/ }, "every action is pinned by a commit (#{uses.size})")

rules = steps.find { |s| (s["with"] || {})["repository"] == "semgrep/semgrep-rules" }
check.call(!rules.nil?, "a step fetches the rules from semgrep/semgrep-rules")
rules_with = (rules || {})["with"] || {}
check.call(rules_with["ref"].to_s =~ /\A[0-9a-f]{40}\z/, "the rules are fetched at an exact commit")
check.call(rules_with["persist-credentials"] == false, "the rules checkout keeps no credentials")

scan = steps.find { |s| s["run"].to_s.include?("semgrep scan") }
check.call(!scan.nil?, "a step runs semgrep scan")
command = (scan || {})["run"].to_s.gsub("\\\n", " ")
check.call(command =~ %r{docker run [^\n]*semgrep/semgrep:\d+\.\d+\.\d+@sha256:[0-9a-f]{64}\s},
           "the scanner is the semgrep/semgrep image at an exact version and digest")
check.call(command.include?("--network none"), "the scanner runs with no network")
check.call(command.include?("--error"), "any finding fails the step (--error)")
check.call(command.include?("--metrics=off"), "the scanner sends no metrics")

config = command[/--config\s+(\S+)/, 1].to_s
check.call(config.start_with?((rules_with["path"] || "\0").to_s + "/"), "the scan reads the fetched rules (#{config})")

after = command.split("semgrep scan", 2)[1].to_s.split
targets = []
i = 0
while i < after.size
  if after[i].start_with?("--")
    i += after[i].include?("=") || %w[--error --disable-version-check].include?(after[i]) ? 1 : 2
  else
    targets << after[i]
    i += 1
  end
end
check.call(targets == %w[administrator site api plugins modules script.php],
           "the scan reads administrator, site, api, plugins, modules and script.php (#{targets.join(' ')})")

excluded = command.scan(/--exclude-rule\s+(\S+)/).flatten
check.call(excluded.sort == %w[semgrep-rules.php.lang.security.unlink-use semgrep-rules.php.lang.security.unserialize-use
                               semgrep-rules.php.lang.security.weak-crypto],
           "exactly the three rules that flag every call are left out (#{excluded.size})")

File.write(args_out, after.join("\n") + "\n")

results.each { |ok, label| puts "#{ok ? 'PASS' : 'FAIL'} #{label}" }
exit(results.all? { |ok, _| ok } ? 0 : 1)
RUBY
cat "$WORK/ruby.out"
failures=$((failures + $(grep -c '^FAIL' "$WORK/ruby.out")))
grep -q '^PASS\|^FAIL' "$WORK/ruby.out" || fail "part 1 could not read the workflow"

# Part 2: the job's own command, run by a local Semgrep of the same version.
if [ -n "${SEMGREP_BIN:-}" ]; then
  if [ ! -d "$ROOT/.semgrep-rules/php/lang/security" ]; then
    fail "part 2: the rules are not in .semgrep-rules (fetch them at the job's commit first)"
  else
    want="$(grep -o 'semgrep/semgrep:[0-9.]*' "$WORKFLOW" | head -1 | cut -d: -f2)"
    have="$("$SEMGREP_BIN" --version 2>/dev/null | tail -1)"
    [ "$have" = "$want" ] && pass "part 2: SEMGREP_BIN is Semgrep $have, the job's version" \
      || fail "part 2: SEMGREP_BIN is Semgrep ${have:-unknown}, the job runs $want"
    args=()
    while IFS= read -r arg; do args+=("$arg"); done < "$WORK/args"
    (cd "$ROOT" && "$SEMGREP_BIN" scan "${args[@]}" > "$WORK/clean.log" 2>&1)
    rc=$?
    [ $rc -eq 0 ] && pass "part 2: the job's command passes on this checkout" \
      || fail "part 2: the job's command exits $rc on this checkout (see its findings below)"
    [ $rc -eq 0 ] || grep -E 'semgrep-rules\.|Ran ' "$WORK/clean.log"

    mkdir -p "$WORK/planted"
    (cd "$ROOT" && tar -cf - administrator site api plugins modules script.php .semgrep-rules) | tar -xf - -C "$WORK/planted"
    printf '<?php\n\n\\defined('"'"'_JEXEC'"'"') or die;\n\necho $_GET['"'"'planted'"'"'];\n' > "$WORK/planted/site/planted.php"
    (cd "$WORK/planted" && "$SEMGREP_BIN" scan "${args[@]}" > "$WORK/planted.log" 2>&1)
    rc=$?
    [ $rc -eq 1 ] && grep -q 'injection.echoed-request' "$WORK/planted.log" \
      && pass "part 2: a request value echoed in site/planted.php fails the command (echoed-request)" \
      || fail "part 2: a request value echoed in site/planted.php did not fail the command (exit $rc)"
  fi
else
  echo "SKIP part 2: SEMGREP_BIN is not set"
fi

if [ "$failures" -eq 0 ]; then
  echo "php-security job: every check passed"
else
  echo "php-security job: $failures check(s) failed"
fi
exit $((failures > 0))
