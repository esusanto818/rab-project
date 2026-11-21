#!/bin/bash
BASE_URL="http://127.0.0.1:8098/php-crud/dashboardCRD.php"
ACTIONS=("get_projects" "read_tax_rates" "read_project_ai&project_id=1")

FAIL=0
for action in "${ACTIONS[@]}"; do
    echo "Testing action: $action"
    response=$(curl -s "${BASE_URL}?action=${action}")
    success=$(echo $response | jq '.success')
    if [ "$success" != "true" ]; then
        echo "FAILED: $action"
        echo "Response: $response"
        FAIL=$((FAIL+1))
    else
        echo "PASSED: $action"
    fi
done

echo "Total Fails: $FAIL"
exit $FAIL
