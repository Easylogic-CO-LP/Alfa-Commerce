#!/usr/bin/env bash
# Tests for the review guard in .github/workflows/claude-pr-review.yml.
#
# Run from anywhere: bash tests/ci/review-guard-test.sh
# Needs bash, ruby (its standard library reads the YAML) and, for the live part, gh signed in to GitHub.
# Exits non-zero when any check fails.
#
# Part 1 reads the workflow file and checks its shape: triggers, permissions, concurrency, pinned actions,
#        persist-credentials, and the guard as the first step.
# Part 2 takes the job condition from the file and evaluates it against fixture events: every author
#        association, pull requests from this repository and from a fork, comments on a pull request and
#        on a plain issue, with and without the phrase.
# Part 3 takes the guard step's script from the file and runs it against a stand-in gh that answers
#        like the permission endpoint, then live and read only against two real accounts.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/claude-pr-review.yml"
REPO="Easylogic-CO-LP/Alfa-Commerce"
ADMIN_LOGIN="${ADMIN_LOGIN:-niosme}"
OUTSIDER_LOGIN="octocat"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
failures=0

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; failures=$((failures + 1)); }

# Parts 1 and 2. Also writes the guard step's script to $WORK/guard-step.sh for part 3.
ruby --disable-gems - "$WORKFLOW" "$REPO" "$WORK/guard-step.sh" > "$WORK/ruby.out" 2>&1 <<'RUBY'
require "yaml"
require "json"

workflow, repo, step_out = ARGV
results = []
check = lambda { |ok, label| results << [ok, label] }

# The subset of the GitHub Actions expression language the job condition uses: string literals,
# context paths, ==, !=, !, &&, ||, parentheses, contains() and fromJSON(). Strings compare without
# case and null, false, 0 and "" are falsy, as GitHub documents.
class Expression
  TOKEN = /\s*(?:(?<str>'(?:[^']|'')*')|(?<op>&&|\|\||==|!=|!|\(|\)|,)|(?<id>[A-Za-z_][A-Za-z0-9_.]*))/

  def initialize(source)
    @tokens = []
    text = source.strip
    pos = 0
    while pos < text.length
      m = TOKEN.match(text, pos)
      raise "cannot read the expression at: #{text[pos, 30]}" unless m && m.begin(0) == pos
      if m[:str] then @tokens << [:str, m[:str][1..-2].gsub("''", "'")]
      elsif m[:op] then @tokens << [:op, m[:op]]
      else @tokens << [:id, m[:id]]
      end
      pos = m.end(0)
    end
  end

  def evaluate(context)
    @context = context
    @i = 0
    value = parse_or
    raise "unexpected text after the expression" if @i < @tokens.length
    value
  end

  def self.truthy?(v)
    !(v.nil? || v == false || v == 0 || v == "")
  end

  private

  def take
    t = @tokens[@i]
    @i += 1
    t
  end

  def accept(op)
    t = @tokens[@i]
    t && t[0] == :op && t[1] == op ? take : nil
  end

  def parse_or
    v = parse_and
    while accept("||")
      r = parse_and
      v = Expression.truthy?(v) ? v : r
    end
    v
  end

  def parse_and
    v = parse_eq
    while accept("&&")
      r = parse_eq
      v = Expression.truthy?(v) ? r : v
    end
    v
  end

  def parse_eq
    v = parse_not
    loop do
      if accept("==") then v = same(v, parse_not)
      elsif accept("!=") then v = !same(v, parse_not)
      else break
      end
    end
    v
  end

  def parse_not
    accept("!") ? !Expression.truthy?(parse_not) : parse_primary
  end

  def parse_primary
    t = take or raise "the expression ends too early"
    if t == [:op, "("]
      v = parse_or
      accept(")") or raise "missing )"
      return v
    end
    return t[1] if t[0] == :str
    raise "unexpected #{t[1]}" unless t[0] == :id
    return call(t[1], arguments) if accept("(")
    case t[1]
    when "true" then true
    when "false" then false
    when "null" then nil
    else t[1].split(".").reduce(@context) { |node, key| node.is_a?(Hash) ? node[key] : nil }
    end
  end

  def arguments
    args = []
    return args if accept(")")
    loop do
      args << parse_or
      return args if accept(")")
      accept(",") or raise "missing , or )"
    end
  end

  def call(name, args)
    case name.downcase
    when "contains"
      haystack, needle = args
      if haystack.is_a?(Array) then haystack.any? { |e| same(e, needle) }
      else haystack.to_s.downcase.include?(needle.to_s.downcase)
      end
    when "fromjson" then JSON.parse(args[0])
    else raise "#{name}() is not part of what this test evaluates"
    end
  end

  def same(a, b)
    a.is_a?(String) && b.is_a?(String) ? a.casecmp(b).zero? : a == b
  end
end

doc = YAML.safe_load(File.read(workflow))
job = doc.fetch("jobs").fetch("claude-review")
steps = job.fetch("steps")
triggers = doc[true] || doc["on"] # YAML 1.1 reads the bare key on as true

# Part 1: the shape of the file.
check.call(triggers.keys.sort == %w[issue_comment pull_request],
           "part 1, triggers are pull_request and issue_comment only (no pull_request_target)")
check.call(job["permissions"] == { "contents" => "read", "id-token" => "write" },
           "part 1, permissions are exactly contents: read and id-token: write")
check.call(doc["permissions"].nil?, "part 1, no workflow level permissions widen the job")
concurrency = job["concurrency"] || {}
check.call(concurrency["cancel-in-progress"] == true &&
           concurrency["group"].to_s.include?("github.event.pull_request.number") &&
           concurrency["group"].to_s.include?("github.event.issue.number"),
           "part 1, one concurrency group per pull request on the job, cancel-in-progress")
check.call(doc["concurrency"].nil?, "part 1, no workflow level concurrency (a skipped run would cancel a review)")
guard = steps.first["name"] == "Require write access" ? steps.first["run"].to_s : ""
check.call(!guard.empty?, "part 1, the guard is the first step")
check.call(!guard.empty? && !guard.include?("${{"),
           "part 1, the guard script takes its values from env, no expression inside the script")
uses = steps.map { |s| s["uses"] }.compact
check.call(!uses.empty? && uses.all? { |u| u =~ /@[0-9a-f]{40}\z/ },
           "part 1, every action is pinned to a full commit SHA: #{uses.join(', ')}")
checkout = steps.find { |s| s["uses"].to_s.start_with?("actions/checkout@") }
check.call(checkout && checkout.fetch("with", {})["persist-credentials"] == false,
           "part 1, checkout does not keep the token in the git config")

# Part 2: the job condition against fixture events.
condition = Expression.new(job.fetch("if"))
associations = %w[OWNER MEMBER COLLABORATOR CONTRIBUTOR FIRST_TIME_CONTRIBUTOR FIRST_TIMER MANNEQUIN NONE]
# CONTRIBUTOR passes this layer because private organisation members appear as one; the guard step decides.
trusted = %w[OWNER MEMBER COLLABORATOR CONTRIBUTOR]

pull_request = lambda do |association, head_repo|
  { "github" => { "event_name" => "pull_request", "repository" => repo,
                  "event" => { "pull_request" => { "number" => 7, "author_association" => association,
                                                   "head" => { "repo" => head_repo && { "full_name" => head_repo } } } } } }
end
comment = lambda do |association, body, on_pull_request|
  issue = { "number" => 7 }
  issue["pull_request"] = { "url" => "https://api.github.com/repos/#{repo}/pulls/7" } if on_pull_request
  { "github" => { "event_name" => "issue_comment", "repository" => repo,
                  "event" => { "issue" => issue, "comment" => { "author_association" => association, "body" => body } } } }
end
expect = lambda do |label, context, want|
  got = Expression.truthy?(condition.evaluate(context))
  check.call(got == want, "part 2, #{label}: #{got ? 'runs' : 'skipped'}")
end

associations.each do |a|
  expect.call("pull_request from this repository, #{a}", pull_request.call(a, repo), trusted.include?(a))
  expect.call("pull_request from a fork, #{a}", pull_request.call(a, "someone/Alfa-Commerce"), false)
  expect.call("@claude comment on a pull request, #{a}", comment.call(a, "@claude please review", true), trusted.include?(a))
end
expect.call("pull_request whose fork was deleted, MEMBER", pull_request.call("MEMBER", nil), false)
expect.call("comment on a pull request without the phrase, MEMBER", comment.call("MEMBER", "looks good", true), false)
expect.call("@claude comment on a plain issue, MEMBER", comment.call("MEMBER", "@claude please look", false), false)
expect.call("@Claude in another case, MEMBER", comment.call("MEMBER", "@Claude review", true), true)
expect.call("other event, MEMBER", { "github" => { "event_name" => "push", "repository" => repo, "event" => {} } }, false)

File.write(step_out, guard)
results.each { |ok, label| puts "#{ok ? 'PASS' : 'FAIL'} #{label}" }
exit(results.all?(&:first) ? 0 : 1)
RUBY
ruby_status=$?
cat "$WORK/ruby.out"
ruby_failures=$(grep -c '^FAIL' "$WORK/ruby.out")
failures=$((failures + ruby_failures))
if [ "$ruby_status" -ne 0 ] && [ "$ruby_failures" -eq 0 ]; then
    fail "parts 1 and 2 did not run (ruby exit $ruby_status)"
fi
if [ ! -s "$WORK/guard-step.sh" ]; then
    fail "part 3, the guard step's script could not be read from the workflow"
    echo "$failures failure(s)"
    exit 1
fi

# Part 3: the guard step itself, run the way the runner runs a run step without a shell key (bash -e).
# expect_guard <label> <0 to pass, 1 to stop> <actor> <PATH> [text the output must contain]
expect_guard() {
    local label="$1" want="$2" actor="$3" path="$4" text="${5:-}" got
    ACTOR="$actor" REPO="$REPO" PATH="$path" bash -e "$WORK/guard-step.sh" > "$WORK/out" 2>&1
    got=$?
    if [ "$want" -eq 0 ] && [ "$got" -ne 0 ]; then
        fail "part 3, $label: stopped (exit $got): $(tr '\n' ' ' < "$WORK/out")"
    elif [ "$want" -ne 0 ] && [ "$got" -eq 0 ]; then
        fail "part 3, $label: let through: $(tr '\n' ' ' < "$WORK/out")"
    elif [ -n "$text" ] && ! grep -q "$text" "$WORK/out"; then
        fail "part 3, $label: output lacks \"$text\": $(tr '\n' ' ' < "$WORK/out")"
    else
        pass "part 3, $label: exit $got, $(tr '\n' ' ' < "$WORK/out")"
    fi
}

# A stand-in for gh that records its arguments and answers like the permission endpoint.
mkdir -p "$WORK/bin"
cat > "$WORK/bin/gh" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" > "$STUB_LOG"
if [ "$STUB_PERMISSION" = "error" ]; then
    echo "gh: Not Found (HTTP 404)" >&2
    exit 1
fi
printf '%s\n' "$STUB_PERMISSION"
STUB
chmod +x "$WORK/bin/gh"
export STUB_LOG="$WORK/gh-call"
STUB_PATH="$WORK/bin:$PATH"

STUB_PERMISSION=admin expect_guard "admin" 0 someone "$STUB_PATH" "has admin access"
STUB_PERMISSION=write expect_guard "write (the endpoint also reports maintain as write)" 0 someone "$STUB_PATH" "has write access"
STUB_PERMISSION=read expect_guard "read (also triage, and any account on a public repository)" 1 someone "$STUB_PATH" "has read access"
STUB_PERMISSION=none expect_guard "none" 1 someone "$STUB_PATH" "has none access"
STUB_PERMISSION= expect_guard "empty answer" 1 someone "$STUB_PATH" "has no access"
STUB_PERMISSION=error expect_guard "the API call fails (a bot or a deleted account)" 1 someone "$STUB_PATH" "Not Found"

wanted_call="api repos/$REPO/collaborators/someone/permission --jq .permission"
if [ "$(cat "$STUB_LOG")" = "$wanted_call" ]; then
    pass "part 3, the step asks: gh $wanted_call"
else
    fail "part 3, the step asked: gh $(cat "$STUB_LOG"), wanted: gh $wanted_call"
fi

# Live and read only: the same script with the real gh.
if ! command -v gh > /dev/null 2>&1; then
    fail "part 3 live, gh is not installed"
else
    expect_guard "live, $ADMIN_LOGIN" 0 "$ADMIN_LOGIN" "$PATH" "has admin access"
    expect_guard "live, $OUTSIDER_LOGIN" 1 "$OUTSIDER_LOGIN" "$PATH" "has read access"
fi

echo "$failures failure(s)"
[ "$failures" -eq 0 ]
