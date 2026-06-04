#!/bin/bash
php -d memory_limit=256M -S 0.0.0.0:${PORT:-80} -t .
