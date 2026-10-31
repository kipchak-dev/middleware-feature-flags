#!/usr/bin/env bash
# Starts fresh Unleash, Flagsmith and GrowthBook servers in Docker, creates the same two flags in each, and writes
# the keys the integration tests need to tests/Integration/.keys.env.
#
#   beta-api   on for 30% of users, sticky on the user id (the consumer)
#   pro-only   on for attribute plan = "pro"
#
# Stop them with: docker compose -f tests/Integration/compose.yaml down -v
set -euo pipefail

cd "$(dirname "$0")"
KEYS=.keys.env
JSON=(-H "Content-Type: application/json")

docker compose down -v --remove-orphans >/dev/null 2>&1 || true
docker compose up -d >/dev/null 2>&1

wait_for() {
    for _ in $(seq 1 90); do
        [ "$(curl -s -o /dev/null -w '%{http_code}' "$2")" = 200 ] && return 0
        sleep 2
    done
    echo "$1 did not start" >&2
    exit 1
}
wait_for Unleash http://localhost:54243/health
wait_for Flagsmith http://localhost:58001/health/liveness/
wait_for GrowthBook http://localhost:53101/healthcheck

# Unleash: the tokens are created at start-up by INIT_*_API_TOKENS in compose.yaml.
U=http://localhost:54243/api/admin/projects/default/features
UA=(-H "Authorization: *:*.kipchak-test-admin-token" "${JSON[@]}")
for flag in beta-api pro-only; do
    curl -sf "${UA[@]}" -X POST "$U" -d "{\"name\":\"$flag\"}" >/dev/null
done
curl -sf "${UA[@]}" -X POST "$U/beta-api/environments/development/strategies" -d '{
    "name": "flexibleRollout", "parameters": {"rollout": "30", "stickiness": "userId", "groupId": "beta-api"}
}' >/dev/null
curl -sf "${UA[@]}" -X POST "$U/pro-only/environments/development/strategies" -d '{
    "name": "flexibleRollout", "parameters": {"rollout": "100", "stickiness": "default", "groupId": "pro-only"},
    "constraints": [{"contextName": "plan", "operator": "IN", "values": ["pro"]}]
}' >/dev/null
for flag in beta-api pro-only; do
    curl -sf "${UA[@]}" -X POST "$U/$flag/environments/development/on" >/dev/null
done

# Flagsmith: a user, organisation, project and environment, then the features, their segments and a server key.
F=http://localhost:58001/api/v1
curl -sf "${JSON[@]}" -X POST $F/auth/users/ -d '{"email": "admin@example.com", "password": "Kipchak-test-password-1",
    "re_password": "Kipchak-test-password-1", "first_name": "Test", "last_name": "Admin"}' >/dev/null
FLAGSMITH_ADMIN_TOKEN=$(curl -sf "${JSON[@]}" -X POST $F/auth/login/ \
    -d '{"email": "admin@example.com", "password": "Kipchak-test-password-1"}' | jq -r .key)
FA=(-H "Authorization: Token $FLAGSMITH_ADMIN_TOKEN" "${JSON[@]}")
ORG=$(curl -sf "${FA[@]}" -X POST $F/organisations/ -d '{"name": "Kipchak"}' | jq -r .id)
PROJECT=$(curl -sf "${FA[@]}" -X POST $F/projects/ -d "{\"name\": \"Flags\", \"organisation\": $ORG}" | jq -r .id)
ENVIRONMENT=$(curl -sf "${FA[@]}" -X POST $F/environments/ -d "{\"name\": \"Development\", \"project\": $PROJECT}")
ENV_ID=$(echo "$ENVIRONMENT" | jq -r .id)
FLAGSMITH_KEY=$(echo "$ENVIRONMENT" | jq -r .api_key)

# A feature that is off, switched on for one segment.
flagsmith_feature() {
    local feature segment override
    feature=$(curl -sf "${FA[@]}" -X POST $F/projects/$PROJECT/features/ -d "{\"name\": \"$1\", \"default_enabled\": false}" | jq -r .id)
    segment=$(curl -sf "${FA[@]}" -X POST $F/projects/$PROJECT/segments/ -d "{\"name\": \"$1\", \"project\": $PROJECT,
        \"rules\": [{\"type\": \"ALL\", \"conditions\": [], \"rules\": [{\"type\": \"ANY\", \"rules\": [],
        \"conditions\": [$2]}]}]}" | jq -r .id)
    override=$(curl -sf "${FA[@]}" -X POST $F/features/feature-segments/ \
        -d "{\"feature\": $feature, \"segment\": $segment, \"environment\": $ENV_ID}" | jq -r .id)
    curl -sf "${FA[@]}" -X POST $F/features/featurestates/ -d "{\"feature\": $feature, \"environment\": $ENV_ID,
        \"feature_segment\": $override, \"enabled\": true}" >/dev/null
}
flagsmith_feature beta-api '{"operator": "PERCENTAGE_SPLIT", "property": null, "value": "30"}'
flagsmith_feature pro-only '{"property": "plan", "operator": "EQUAL", "value": "pro"}'
FLAGSMITH_SERVER_KEY=$(curl -sf "${FA[@]}" -X POST "$F/environments/$FLAGSMITH_KEY/api-keys/" \
    -d '{"name": "kipchak-tests"}' | jq -r .key)

# GrowthBook: the first user, an admin API key, the features, and an SDK connection for the PHP SDK.
G=http://localhost:53101
GROWTHBOOK_JWT=$(curl -sf "${JSON[@]}" -X POST $G/auth/firsttime -d '{"companyname": "Kipchak",
    "email": "admin@example.com", "name": "Admin", "password": "Kipchak-test-password-1"}' | jq -r .token)
ORG=$(curl -sf -H "Authorization: Bearer $GROWTHBOOK_JWT" $G/user | jq -r '.organizations[0].id')
SECRET=$(curl -sf -H "Authorization: Bearer $GROWTHBOOK_JWT" -H "X-Organization: $ORG" "${JSON[@]}" -X POST $G/keys \
    -d '{"description": "kipchak tests", "type": "admin"}' | jq -r '.key.key // .key')
GA=(-H "Authorization: Bearer $SECRET" "${JSON[@]}")
curl -sf "${GA[@]}" -X POST $G/api/v1/features -d '{"id": "beta-api", "owner": "admin@example.com",
    "valueType": "boolean", "defaultValue": "false", "environments": {"production": {"enabled": true, "rules": [
    {"type": "rollout", "value": "true", "coverage": 0.3, "hashAttribute": "id", "enabled": true}]}}}' >/dev/null
curl -sf "${GA[@]}" -X POST $G/api/v1/features -d '{"id": "pro-only", "owner": "admin@example.com",
    "valueType": "boolean", "defaultValue": "false", "environments": {"production": {"enabled": true, "rules": [
    {"type": "force", "condition": "{\"plan\":\"pro\"}", "value": "true", "enabled": true}]}}}' >/dev/null
GROWTHBOOK_CLIENT_KEY=$(curl -sf "${GA[@]}" -X POST $G/api/v1/sdk-connections \
    -d '{"name": "kipchak-tests", "language": "php", "environment": "production"}' | jq -r .sdkConnection.key)

cat > $KEYS <<KEYS
UNLEASH_URL=http://localhost:54243/api
UNLEASH_TOKEN=*:development.kipchak-test-client-token
FLAGSMITH_URL=http://localhost:58001/api/v1
FLAGSMITH_ENVIRONMENT_KEY=$FLAGSMITH_KEY
FLAGSMITH_SERVER_KEY=$FLAGSMITH_SERVER_KEY
GROWTHBOOK_API_HOST=http://localhost:53101
GROWTHBOOK_CLIENT_KEY=$GROWTHBOOK_CLIENT_KEY
KEYS

echo "Unleash, Flagsmith and GrowthBook are ready; keys are in tests/Integration/$KEYS"
