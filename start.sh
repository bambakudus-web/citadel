#!/bin/bash
php -d memory_limit=256M -d max_execution_time=60 -S 0.0.0.0:${PORT:-80} -t . router.php
