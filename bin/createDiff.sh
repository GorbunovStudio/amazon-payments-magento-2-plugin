#!/bin/sh

set -e

originalPath="$(pwd)"

cd "$(dirname "$0")"

cd ..

targetBranch="master"
staged=0

while [ $# -gt 0 ]; do
    case $1 in
        --target-branch)
            targetBranch="$2"
            shift 2
            ;;
        --staged)
            staged=1
            shift
            ;;
        *)
            shift
            ;;
    esac
done

if [ "$staged" = "1" ]; then
    git diff --cached $(git merge-base HEAD "$targetBranch") > changes.diff
else
    git diff $(git merge-base HEAD "$targetBranch")..HEAD > changes.diff
fi

cd "$originalPath"
