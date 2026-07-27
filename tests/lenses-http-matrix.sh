#!/usr/bin/env bash
# HTTP matrix for MarkdownToFieldsLenses, run against a live ProcessWire
# install (default: the pwlayground ddev site). Every case asserts status,
# content type, and a body marker. Exits non-zero on any failure.
#
#   BASE_URL=https://example.com ./tests/lenses-http-matrix.sh
#
# Expects the playground fixtures: /about/ + about.md (sections hero, faq),
# /services/ + services.md, home.md, site.md (nav), /nofile/ without a
# markdown file, /draft/ unpublished with draft.md present.

set -u
BASE_URL="${BASE_URL:-https://pwlayground.ddev.site}"
BROWSER_ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'

pass=0
fail=0

check() {
  local label="$1" url="$2" accept="$3" want_status="$4" want_type="$5" want_marker="$6" forbid_marker="${7:-}"
  local tmp headers body status ctype
  tmp=$(mktemp)
  headers=$(curl -sk -D - -o "$tmp" -H "Accept: $accept" "$url")
  body=$(cat "$tmp"); rm -f "$tmp"
  status=$(printf '%s' "$headers" | head -1 | awk '{print $2}')
  ctype=$(printf '%s' "$headers" | grep -i '^content-type:' | tr -d '\r' | head -1)

  local ok=1
  [ "$status" = "$want_status" ] || ok=0
  printf '%s' "$ctype" | grep -qi "$want_type" || ok=0
  printf '%s' "$body" | grep -q "$want_marker" || ok=0
  if [ -n "$forbid_marker" ]; then
    printf '%s' "$body" | grep -q "$forbid_marker" && ok=0
  fi

  if [ "$ok" = 1 ]; then
    pass=$((pass + 1))
    echo "PASS  $label"
  else
    fail=$((fail + 1))
    echo "FAIL  $label"
    echo "      url: $url (Accept: $accept)"
    echo "      got status=$status type=$ctype"
    printf '%s\n' "$body" | head -5 | sed 's/^/      body: /'
  fi
}

check_header() {
  local label="$1" url="$2" accept="$3" want_header="$4"
  local headers
  headers=$(curl -sk -D - -o /dev/null -H "Accept: $accept" "$url")
  if printf '%s' "$headers" | grep -qi "$want_header"; then
    pass=$((pass + 1))
    echo "PASS  $label"
  else
    fail=$((fail + 1))
    echo "FAIL  $label"
    echo "      url: $url — missing header matching: $want_header"
  fi
}

echo "== raw markdown =="
check "agent .md URL is raw markdown"        "$BASE_URL/about.md"            '*/*'             200 'text/markdown' '# About the Playground'
check ".md frontmatter has curated fields"   "$BASE_URL/about.md"            '*/*'             200 'text/markdown' 'title: About the Playground'
check "?format=md is raw markdown"           "$BASE_URL/about/?format=md"    '*/*'             200 'text/markdown' '# About the Playground'
check "?format=md stays raw for browsers"    "$BASE_URL/about/?format=md"    "$BROWSER_ACCEPT" 200 'text/markdown' '# About the Playground' '<html'
check "Accept: text/markdown negotiates raw" "$BASE_URL/about/"              'text/markdown'   200 'text/markdown' '# About the Playground'
check "homepage at /home.md"                 "$BASE_URL/home.md"             '*/*'             200 'text/markdown' '# Welcome to the Playground'
check "internal links absolutized"           "$BASE_URL/about.md"            '*/*'             200 'text/markdown' 'link to services](https://'
check "bare image refs resolve to assets"    "$BASE_URL/about.md"            '*/*'             200 'text/markdown' 'tiny test image](https://.*/site/assets/files/'

echo "== reader view =="
check "browser .md URL is reader HTML"       "$BASE_URL/about.md"            "$BROWSER_ACCEPT" 200 'text/html'     '<h1>About the Playground</h1>'
check "reader nav from site.md"              "$BASE_URL/about.md"            "$BROWSER_ACCEPT" 200 'text/html'     'services.md'
check "reader content links stay in reader"  "$BASE_URL/services.md"         "$BROWSER_ACCEPT" 200 'text/html'     'href=".*about\.md#faq"'
check "reader homepage works"                "$BASE_URL/home.md"             "$BROWSER_ACCEPT" 200 'text/html'     '<h1>Welcome to the Playground</h1>'

echo "== fragments =="
check "?section returns section html"        "$BASE_URL/about/?section=hero" '*/*'             200 'text/html'     'About the Playground' 'Frequently asked'
check "?section multiple sections"           "$BASE_URL/about/?section=hero,faq" '*/*'         200 'text/html'     'Frequently asked'
check "?section with format=md is markdown"  "$BASE_URL/about/?section=faq&format=md" '*/*'    200 'text/markdown' '## Frequently asked' 'About the Playground'
check "?section unknown section 404s"        "$BASE_URL/about/?section=nope" '*/*'             404 'text/html'     'Unknown section'
check "fragment md resolves bare images too" "$BASE_URL/about/?section=hero&format=md" '*/*'   200 'text/markdown' 'tiny test image](https://.*/site/assets/files/'
check "?part is left to projects (no hijack)" "$BASE_URL/about/?part=header" '*/*'             200 'text/html'     '' 'Unknown section'

echo "== 404s and exposure =="
check "missing page .md 404s in markdown"    "$BASE_URL/nope.md"             '*/*'             404 'text/markdown' '404 Not Found'
check "missing page .md 404s in reader"      "$BASE_URL/nope.md"             "$BROWSER_ACCEPT" 404 'text/html'     '404 Not Found'
check "page without markdown file 404s"      "$BASE_URL/nofile.md"           '*/*'             404 'text/markdown' '404 Not Found'
check "unpublished page never leaks"         "$BASE_URL/draft.md"            '*/*'             404 'text/markdown' '404 Not Found' 'Secret Draft'
check "unpublished ?format=md never leaks"   "$BASE_URL/draft/?format=md"    '*/*'             404 ''              '' 'Secret Draft'

echo "== headers =="
check_header "raw response is noindex"       "$BASE_URL/about.md"            '*/*'             'X-Robots-Tag: noindex'
check_header "raw response has canonical"    "$BASE_URL/about.md"            '*/*'             'rel="canonical"'
check_header "raw response has ETag"         "$BASE_URL/about.md"            '*/*'             '^etag:'
check_header "normal render advertises md"   "$BASE_URL/about/"              "$BROWSER_ACCEPT" 'rel="alternate"; type="text/markdown"'
check_header "guest lens response is cacheable" "$BASE_URL/about.md"         '*/*'             'Cache-Control: public, max-age=300'
check_header "lens 404 is not cached"        "$BASE_URL/nope.md"             '*/*'             'Cache-Control: no-cache'

echo "== conditional GET =="
etag=$(curl -sk -D - -o /dev/null "$BASE_URL/about.md" | grep -i '^etag:' | tr -d '\r' | cut -d' ' -f2)
status304=$(curl -sk -o /dev/null -w '%{http_code}' -H "If-None-Match: $etag" "$BASE_URL/about.md")
if [ "$status304" = "304" ]; then
  pass=$((pass + 1)); echo "PASS  If-None-Match returns 304"
else
  fail=$((fail + 1)); echo "FAIL  If-None-Match returns 304 (got $status304, etag=$etag)"
fi

echo
echo "passed: $pass  failed: $fail"
[ "$fail" = 0 ]
