#!/bin/bash
# rsp-runner — execute the R.S.P. plan task after task, autonomously, in the
# isolated worktree + Docker stack (arovolife-rsp, http://localhost:8094).
#
#   run.sh                 run every remaining task in sequence (stops on a blocker)
#   run.sh --once          run only the next task
#   run.sh --task 8+9      run a specific task
#   run.sh --dry-run       preflight + print the prompt; Claude not started
#   run.sh --smoke         preflight + one-turn Claude call to prove auth
#
# Pause:  touch scripts/rsp-runner/PAUSE   (remove to resume)
# Logs:   scripts/rsp-runner/logs/<date>.log and <date>.task-<n>.out
set -euo pipefail

REPO="/Users/preetham/Documents/arovolife/arovolife/arovolife-rsp"
DIR="$REPO/scripts/rsp-runner"
STATE="$DIR/state.json"
PROMPT_TPL="$DIR/task-prompt.md"
LOG_DIR="$DIR/logs"
LOCK="$DIR/.lock"
PAUSE="$DIR/PAUSE"
MAX_HOURS="${RSP_MAX_HOURS:-6}"        # per task
MODEL="${RSP_MODEL:-claude-fable-5-1}"
APP_URL="http://localhost:8094/"
COMPOSE="docker compose -f $REPO/docker/docker-compose.rsp.yml"
APP_CONTAINER="arovolife-rsp-app"

export PATH="$HOME/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

MODE="loop"
FORCE_TASK=""
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) MODE="dry" ;;
    --smoke) MODE="smoke" ;;
    --once) MODE="once" ;;
    --task) shift; FORCE_TASK="$1"; MODE="once" ;;
    *) echo "unknown arg $1" >&2; exit 2 ;;
  esac
  shift
done

mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/$(date +%Y-%m-%d).log"
exec >>"$LOG" 2>&1
echo "=== rsp-runner $(date '+%F %T') mode=$MODE ==="

notify() {
  /usr/bin/osascript -e "display notification \"$2\" with title \"arovolife rsp-runner\" subtitle \"$1\"" >/dev/null 2>&1 || true
}
fail() { echo "FAIL: $1"; notify "Stopped" "$1"; exit 1; }

# --- lock -----------------------------------------------------------------
if ! mkdir "$LOCK" 2>/dev/null; then
  if [ -n "$(find "$LOCK" -maxdepth 0 -mmin +$(( (MAX_HOURS + 1) * 60 )) 2>/dev/null)" ]; then
    echo "stale lock removed"; rmdir "$LOCK" 2>/dev/null || true; mkdir "$LOCK"
  else
    echo "another run is in progress; exiting"; exit 0
  fi
fi
trap 'rmdir "$LOCK" 2>/dev/null || true' EXIT

cd "$REPO"
command -v claude >/dev/null || fail "claude CLI not on PATH"
command -v docker >/dev/null || fail "docker not on PATH"
command -v python3 >/dev/null || fail "python3 not on PATH"

BRANCH="$(python3 -c "import json;print(json.load(open('$STATE'))['branch'])")"
PLAN="$(python3 -c "import json;print(json.load(open('$STATE'))['plan'])")"

# --- one task -----------------------------------------------------------------
run_task() {
  local TODAY TASK TASK_SLUG ATTEMPT GIT_STATUS SYNC_NOTE PROMPT CLAUDE_PID WATCHDOG RC REPORT OUTCOME code
  TODAY="$(date +%Y-%m-%d)"

  [ -f "$PAUSE" ] && { echo "PAUSED: $(cat "$PAUSE")"; notify "Paused" "Remove scripts/rsp-runner/PAUSE to resume"; return 2; }

  # Stack up and answering.
  if ! $COMPOSE ps --format '{{.Name}} {{.Status}}' | grep -q "^$APP_CONTAINER Up"; then
    echo "app container down; starting stack"; $COMPOSE up -d || fail "docker compose up failed"; sleep 45
  fi
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$APP_URL" || true)"
  if [ "$code" != "200" ] && [ "$code" != "302" ]; then
    sleep 30; code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$APP_URL" || true)"
    [ "$code" = "200" ] || [ "$code" = "302" ] || fail "app not answering on $APP_URL (http $code)"
  fi

  # Right branch in the worktree.
  [ "$(git branch --show-current)" = "$BRANCH" ] || fail "worktree is on $(git branch --show-current), expected $BRANCH"

  # Keep up with main: merge local main and origin/main before every task.
  SYNC_NOTE="main merged: nothing new"
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    SYNC_NOTE="main NOT merged this run: the tree had uncommitted changes from a previous attempt"
  else
    git fetch origin main >/dev/null 2>&1 || echo "fetch origin/main failed (offline?); local main only"
    local SRC
    for SRC in main origin/main; do
      git rev-parse --verify -q "$SRC" >/dev/null || continue
      if [ -n "$(git rev-list "HEAD..$SRC" 2>/dev/null)" ]; then
        echo "merging $SRC ($(git rev-list --count "HEAD..$SRC") commits)"
        if git merge --no-edit "$SRC" >/dev/null 2>&1; then
          SYNC_NOTE="main merged from $SRC — $(git log --oneline -3 "$SRC" | tr '\n' ';')"
        else
          git merge --abort || true
          mkdir -p docs/plans/rsp-reports
          printf '# %s — BLOCKED before start\n\n**Outcome:** BLOCKED\n\nMerging `%s` into `%s` conflicts. Resolve by hand in the worktree `%s`, commit, then run `bash scripts/rsp-runner/run.sh`.\n' \
            "$TODAY" "$SRC" "$BRANCH" "$REPO" > "docs/plans/rsp-reports/$TODAY-merge-conflict.md"
          fail "merge conflict with $SRC; see docs/plans/rsp-reports/$TODAY-merge-conflict.md"
        fi
      fi
    done
  fi
  echo "$SYNC_NOTE"

  # Pick the task.
  if [ -n "$FORCE_TASK" ]; then
    TASK="$FORCE_TASK"
  else
    TASK="$(python3 - "$STATE" <<'PY'
import json, sys
s = json.load(open(sys.argv[1]))
nxt = [] if s.get("completed") else [t for t in s["order"] if t not in s["done"]]
print(nxt[0] if nxt else "")
PY
)"
  fi
  if [ -z "$TASK" ]; then
    echo "all tasks done"; notify "Complete" "All plan tasks done. Read docs/plans/rsp-reports/FINAL-QA-signoff.md and merge."; return 3
  fi

  ATTEMPT="$(python3 -c "import json;s=json.load(open('$STATE'));print(int(s.get('attempts',{}).get('$TASK',0))+1)")"
  if [ "$ATTEMPT" -gt 3 ]; then
    echo "Task $TASK already failed 3 times; refusing to loop"; echo "Task $TASK failed 3 attempts" > "$PAUSE"; notify "Paused" "Task $TASK failed three times"; return 2
  fi
  TASK_SLUG="$(echo "$TASK" | tr '+' '-')"
  GIT_STATUS="$(git status --short | head -40)"; [ -z "$GIT_STATUS" ] && GIT_STATUS="(clean)"

  PROMPT="$(python3 - "$PROMPT_TPL" "$TODAY" "$TASK" "$TASK_SLUG" "$PLAN" "$BRANCH" "$ATTEMPT" "$GIT_STATUS" "$SYNC_NOTE" "$REPO" <<'PY'
import sys
tpl, date, task, slug, plan, branch, attempt, status, sync, repo = sys.argv[1:11]
t = open(tpl).read()
for k, v in {"__DATE__": date, "__TASK__": task, "__TASK_SLUG__": slug, "__PLAN__": plan, "__BRANCH__": branch,
             "__ATTEMPT__": attempt, "__GIT_STATUS__": status, "__SYNC_NOTE__": sync, "__REPO__": repo}.items():
    t = t.replace(k, v)
print(t)
PY
)"
  echo "task=$TASK attempt=$ATTEMPT branch=$BRANCH model=$MODEL"

  if [ "$MODE" = "dry" ]; then
    echo "--- prompt ---"; echo "$PROMPT"; echo "--- end prompt (dry run) ---"; return 3
  fi

  # Permissions: no blanket bypass. acceptEdits + the explicit allow/deny list
  # in scripts/rsp-runner/settings.json. Project settings are NOT loaded
  # (--setting-sources user), so the repository's PostToolUse hooks — which run
  # Pint/Larastan inside the user's arovolife-app container and would touch the
  # main checkout — never fire here. Anything not allowed (git push, merge,
  # curl, ssh, cloud MCPs, .env writes, rm -rf, the user's containers) is
  # refused in headless mode.
  local -a PERMS=(
    --permission-mode acceptEdits
    --setting-sources user
    --settings "$DIR/settings.json"
  )

  if [ "$MODE" = "smoke" ]; then
    local out
    out="$(claude -p "Reply with exactly: RSP-RUNNER-OK" --model "$MODEL" "${PERMS[@]}" --output-format text --max-turns 1 2>&1 || true)"
    echo "smoke output: $out"
    echo "$out" | grep -q "RSP-RUNNER-OK" && { echo "smoke OK"; return 3; } || fail "smoke test did not get the expected reply"
  fi

  notify "Task $TASK started" "attempt $ATTEMPT on $BRANCH"
  claude -p "$PROMPT" --model "$MODEL" "${PERMS[@]}" --output-format text \
    --append-system-prompt "You are running headless under a runner. There is no user to answer you. Never ask a question; decide and proceed within the guardrails in the prompt." \
    > "$LOG_DIR/$TODAY.task-$TASK_SLUG.out" 2>&1 &
  CLAUDE_PID=$!
  ( sleep $(( MAX_HOURS * 3600 )); kill -0 "$CLAUDE_PID" 2>/dev/null && { echo "watchdog: killing after ${MAX_HOURS}h"; kill "$CLAUDE_PID"; } ) &
  WATCHDOG=$!
  set +e; wait "$CLAUDE_PID"; RC=$?; set -e
  kill "$WATCHDOG" 2>/dev/null || true
  echo "claude exited rc=$RC at $(date '+%F %T')"
  tail -n 30 "$LOG_DIR/$TODAY.task-$TASK_SLUG.out" || true

  REPORT="$(ls -t docs/plans/rsp-reports/"$TODAY"-task-"$TASK_SLUG".md 2>/dev/null | head -1 || true)"
  OUTCOME="$( [ -n "$REPORT" ] && grep -m1 '^\*\*Outcome:\*\*' "$REPORT" | sed 's/\*\*Outcome:\*\* *//' || echo "no report (rc=$RC)")"
  notify "Task $TASK finished" "$OUTCOME"

  # Advanced?  Only then does the loop continue.
  if python3 -c "import json,sys;s=json.load(open('$STATE'));sys.exit(0 if '$TASK' in s['done'] else 1)"; then
    echo "Task $TASK done"; return 0
  fi
  echo "Task $TASK not completed ($OUTCOME); stopping"; return 1
}

# --- main -----------------------------------------------------------------
if [ "$MODE" != "loop" ]; then
  run_task; rc=$?; [ $rc -eq 3 ] && exit 0; exit $rc
fi

for i in $(seq 1 14); do
  set +e; run_task; rc=$?; set -e
  case $rc in
    0) echo "--- next task ---"; sleep 20 ;;
    3) exit 0 ;;        # all done / dry / smoke
    *) exit $rc ;;      # blocked, paused, or failed preflight
  esac
done
