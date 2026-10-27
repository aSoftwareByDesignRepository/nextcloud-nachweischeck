#!/usr/bin/env bash
# NachweisCheck E2E smoke — runs the GAC-1 cycle against a live instance.
# Usage: NW_BASE=http://localhost:8081 NW_USER=admin NW_PASS=... bash scripts/e2e-smoke.sh
set -euo pipefail
BASE="${NW_BASE:-http://localhost:8081}/index.php/apps/nachweischeck/api/v1"
AUTH="${NW_USER}:${NW_PASS}"
H='-H OCS-APIRequest:true -H Accept:application/json -H Content-Type:application/json'
J() { python3 -c "import json,sys;d=json.load(sys.stdin);print(eval('d'+sys.argv[1]))" "$1" 2>/dev/null; }
post(){ curl -s -u "$AUTH" $H -X POST "$BASE$1" -d "$2"; }
get(){ curl -s -u "$AUTH" $H "$BASE$1"; }
step(){ echo "== $1"; }

step "create project"
PID=$(post /projects '{"name":"E2E","funder_name":"BMBF","funder_ref":"FKZ","bew_start":"2026-01-01","bew_end":"2027-06-30","template_key":"anbest-p","financing_mode":"ausgaben"}' | J "['data']['id']")
[ -n "$PID" ] || { echo FAIL create; exit 1; }
step "activate plan v1"
VID=$(get "/projects/$PID/finplan/versions" | J "['data'][0]['id']")
post "/projects/$PID/finplan/versions/$VID/activate" '{"base_version":0}' | grep -q '"state":"active"' || exit 1
step "create+map AP"
AP=$(post "/projects/$PID/workpackages" '{"code":"AP1","title":"Konzept","start_date":"2026-01-01","end_date":"2026-06-30"}' | J "['data']['id']")
POS=$(get "/projects/$PID/finplan/versions/$VID/positions" | J "['data'][0]['id']")
curl -s -u "$AUTH" $H -X PUT "$BASE/projects/$PID/workpackages/$AP/positions" -d "{\"position_ids\":[$POS]}" >/dev/null
step "activate project"
post "/projects/$PID/activate" '{}' | grep -q '"state":"active"' || exit 1
step "rate+time+receipt"
post "/projects/$PID/rates" '{"uid":"'$NW_USER'","rate_minor":6000,"valid_from":"2026-01-01"}' >/dev/null
post "/projects/$PID/time-entries" "{\"ap_id\":$AP,\"entry_date\":\"2026-03-10\",\"minutes\":90,\"note\":\"e2e\"}" | grep -q '"amount_minor":9000' || exit 1
post "/projects/$PID/receipts" "{\"vendor\":\"V\",\"invoice_date\":\"2026-03-15\",\"total_minor\":1000,\"splits\":[{\"position_id\":$POS,\"amount_minor\":1000}]}" | grep -q '"success":true' || exit 1
step "negative: bad split + empty reason"
post "/projects/$PID/receipts" "{\"vendor\":\"X\",\"invoice_date\":\"2026-03-15\",\"total_minor\":1000,\"splits\":[{\"position_id\":$POS,\"amount_minor\":999}]}" | grep -q '"error":"VALIDATION_FAILED"' || exit 1
post "/projects/$PID/deviations" '{"scope":"position","ref_id":1,"kind":"cost","reason_text":""}' | grep -q '"error":"VALIDATION_FAILED"' || exit 1
step "generate nachweis + verify"
NID=$(post "/projects/$PID/nachweise" '{"kind":"final","period_from":"2026-01-01","period_to":"2026-12-31"}' | J "['data']['id']")
get "/projects/$PID/nachweise/$NID" | grep -q '"integrity":true' || exit 1
echo "E2E SMOKE PASS (project $PID, nachweis $NID)"

step "v2 surfaces: cofunder/drawdown/claim/readiness/datev"
CID=$(post "/projects/$PID/cofunders" '{"name":"Land X"}' | J "['data']['id']")
[ -n "$CID" ] || { echo FAIL cofunder; exit 1; }
DD=$(post "/projects/$PID/drawdowns" '{"requested_date":"2026-03-01","amount_minor":50000,"call_ref":"AB-1"}' | J "['data']['id']")
[ -n "$DD" ] || { echo FAIL drawdown; exit 1; }
post "/projects/$PID/claims" '{"kind":"interest","title":"Z","principal_minor":100000,"rate_bp":500,"period_from":"2026-01-01","period_to":"2027-01-01","occurred_date":"2026-12-31"}' | grep -q '"amount_minor":5000' || { echo FAIL claim-interest; exit 1; }
get "/projects/$PID/readiness" | grep -q '"ready"' || { echo FAIL readiness; exit 1; }
get "/projects/$PID/forecast" | grep -q 'burn_rate_minor' || { echo FAIL forecast; exit 1; }
curl -s -u "$AUTH" $H "$BASE/projects/$PID/export/datev" | grep -q 'Umsatz' || { echo FAIL datev; exit 1; }
curl -s -u "$AUTH" $H "$BASE/projects/$PID/antrag?format=pdf" | head -c5 | grep -q '%PDF' || { echo FAIL antrag; exit 1; }
echo "E2E V2 PASS"
