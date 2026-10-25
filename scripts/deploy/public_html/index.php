<?php

/*
| Fallback web root (WEB_ROOT_MODE=forward, docs/DEPLOYMENT.md §6): used only when public_html
| can't be a symlink to app/current/public. This file is copied into public_html on every deploy
| and simply runs the live release's front controller. Static files (build/, storage/) are
| symlinked or copied next to it by scripts/deploy/remote.sh.
*/

require __DIR__.'/../app/current/public/index.php';
