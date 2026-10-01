#!/bin/bash
curl -c /tmp/g1.txt -s http://localhost:8080/profile.php > /tmp/g1p.html
T=$(grep -o 'name="csrf_token" value="[^"]*"' /tmp/g1p.html | head -1 | sed 's/.*value="//;s/"//')
curl -b /tmp/g1.txt -c /tmp/g1.txt -s -X POST --data-urlencode "user_login=admin" --data-urlencode "user_pass=$ADMIN_PASSWORD" --data-urlencode "csrf_token=$T" -o /dev/null http://localhost:8080/validation/auth.php
for p in "/" "/profile.php" "/assembly.php?init=1" "/admin.php?tab=components" "/admin.php?tab=users" "/admin.php?tab=orders"; do
  printf "%-32s %s\n" "$p" "$(curl -b /tmp/g1.txt -s -o /dev/null -w '%{http_code}' "http://localhost:8080$p")"
done
echo "--- admin tabs ---"
curl -b /tmp/g1.txt -s "http://localhost:8080/admin.php?tab=components" | grep -o 'profile-nav-item[^"]*">[^<]*' 
echo "--- profile layout ---"
curl -b /tmp/g1.txt -s http://localhost:8080/profile.php | grep -c 'class="profile-layout"'
