#!/bin/bash

echo "======================================"
echo "Test des APIs de recherche"
echo "======================================"
echo ""

BASE_URL="http://10.193.76.109:8001"

echo "1. Test API basique (/api/v1/products?search=)"
echo "--------------------------------------"
curl -s "${BASE_URL}/api/v1/products?search=MacBook&per_page=2" | python3 -m json.tool | grep -E '"name"|"success"|"total"' | head -5
echo ""

echo "2. Test API intelligente (/api/v1/search?q=)"
echo "--------------------------------------"
curl -s "${BASE_URL}/api/v1/search?q=PC&per_page=2" | python3 -m json.tool | grep -E '"name"|"success"|"total"|"expanded_terms"' -A 3 | head -10
echo ""

echo "3. Test suggestions (auto-complétion)"
echo "--------------------------------------"
curl -s "${BASE_URL}/api/v1/search/suggestions?q=Mac&limit=3" | python3 -m json.tool | grep -E '"suggestions"' -A 5
echo ""

echo "4. Test recherches populaires"
echo "--------------------------------------"
curl -s "${BASE_URL}/api/v1/search/popular?limit=5" | python3 -m json.tool | grep -E '"popular_searches"' -A 7
echo ""

echo "======================================"
echo "Tests terminés ✅"
echo "======================================"
