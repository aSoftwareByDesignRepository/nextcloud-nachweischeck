#!/usr/bin/env bash
# NachweisCheck auditor-seat E2E matrix (US-NW-06, spec §10 adversarial):
# grant → scoped reads logged → out-of-scope section 404 → second grant 409
# → revoke → 404 → expired grant → 404. Requires a Pro license mode
# (nw_license_mode=pro) because the admin grant endpoint is Pro-gated.
#
# Usage:
#   NW_BASE=http://localhost:8081 \
#   NW_USER=admin NW_PASS=... \
#   NW_AUDITOR_USER=stb NW_AUDITOR_PASS=... \
#   bash scripts/e2e-auditor.sh
set -euo pipefail
BASE="${NW_BASE:-http://localhost:8081}/index.php/apps/nachweischeck/api/v1"
AUTH="${NW_USER}:${NW_PASS}"
AUD="${NW_AUDITOR_USER}:${NW_AUDITOR_PASS}"
H='-H OCS-APIRequest:true -H Accept:application/json -H Content-Type:application/json'
J() { python3 -c "import json,sys;d=json.load(sys.stdin);print(eval('d'+sys.argv[1]))" "$1" 2>/dev/null; }
post(){ curl -s -u "$AUTH" $H -X POST "$BASE$1" -d "$2"; }
get(){ curl -s -u "$AUTH" $H "$BASE$1"; }
aget(){ curl -s -u "$AUD" $H "$BASE$1"; }
step(){ echo "== $1"; }
TODAY=$(date +%F); NEXT=$(date -d '+30 days' +%F); YEST=$(date -d '-1 day' +%F)

step "project + plan + activation (as admin)"
PID=$(post /projects '{"name":"AUD-E2E","funder_name":"BKM","funder_ref":"FKZ","bew_start":"2026-01-01","bew_end":"2027-06-30","template_key":"anbest-p","financing_mode":"ausgaben"}' | J "['data']['id']")
[ -n "$PID" ] || { echo FAIL create; exit 1; }
VID=$(get "/projects/$PID/finplan/versions" | J "['data'][0]['id']")
post "/projects/$PID/finplan/versions/$VID/activate" '{"base_version":0}' | grep -q '"state":"active"' || exit 1
AP=$(post "/projects/$PID/workpackages" '{"code":"AP1","title":"Konzept","start_date":"2026-01-01","end_date":"2026-06-30"}' | J "['data']['id']")
POS=$(get "/projects/$PID/finplan/versions/$VID/positions" | J "['data'][0]['id']")
curl -s -u "$AUTH" $H -X PUT "$BASE/projects/$PID/workpackages/$AP/positions" -d "{\"position_ids\":[$POS]}" >/dev/null
post "/projects/$PID/activate" '{}' | grep -q '"state":"active"' || exit 1

step "grant auditor seat scoped to belege+soll_ist"
GID=$(post "/projects/$PID/grants" "{\"nc_uid\":\"$NW_AUDITOR_USER\",\"name\":\"StB\",\"firm\":\"Kanzlei\",\"valid_from\":\"$TODAY\",\"valid_to\":\"$NEXT\",\"scope\":[\"belege\",\"soll_ist\"]}" | J "['data']['id']")
[ -n "$GID" ] || { echo FAIL grant; exit 1; }

step "auditor sees scoped project"
aget "/auditor/projects" | grep -q "\"project_id\":$PID" || { echo FAIL myProjects; exit 1; }
aget "/auditor/projects/$PID" | grep -q '"success":true' || { echo FAIL project read; exit 1; }

step "out-of-scope section → uniform 404"
OUT=$(aget "/auditor/projects/$PID/nachweise")
echo "$OUT" | grep -q '"error":"NOT_FOUND"' || { echo "FAIL scope enforcement: $OUT"; exit 1; }

step "in-scope sections readable + read logged"
aget "/auditor/projects/$PID/belege" | grep -q '"success":true' || { echo FAIL belege; exit 1; }
aget "/auditor/projects/$PID/soll-ist" | grep -q '"success":true' || { echo FAIL soll-ist; exit 1; }
READS=$(get "/projects/$PID/grants/$GID/reads" | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["data"]))')
[ "$READS" -ge 3 ] || { echo "FAIL read log ($READS)"; exit 1; }

step "duplicate live grant → 409"
post "/projects/$PID/grants" "{\"nc_uid\":\"$NW_AUDITOR_USER\",\"name\":\"StB\",\"firm\":\"K\",\"valid_from\":\"$TODAY\",\"valid_to\":\"$NEXT\"}" | grep -q '"error":"GRANT_EXISTS"' || { echo FAIL dup-grant; exit 1; }

step "revoke → auditor locked out (uniform 404, no existence oracle)"
post "/projects/$PID/grants/$GID/revoke" '{}' | grep -q '"success":true' || { echo FAIL revoke; exit 1; }
aget "/auditor/projects/$PID" | grep -q '"error":"NOT_FOUND"' || { echo FAIL post-revoke access; exit 1; }

step "expired grant → uniform 404"
GID2=$(post "/projects/$PID/grants" "{\"nc_uid\":\"$NW_AUDITOR_USER\",\"name\":\"StB\",\"firm\":\"K\",\"valid_from\":\"2020-01-01\",\"valid_to\":\"$YEST\"}" | J "['data']['id']")
[ -n "$GID2" ] || { echo FAIL expired-grant create; exit 1; }
aget "/auditor/projects/$PID" | grep -q '"error":"NOT_FOUND"' || { echo FAIL expired access; exit 1; }

step "non-grantee auditor surface → 404 everywhere"
aget "/auditor/projects/$PID/belege" | grep -q '"error":"NOT_FOUND"' || { echo FAIL non-grantee; exit 1; }

echo "E2E AUDITOR PASS (project $PID, grants $GID/$GID2, reads $READS)"
