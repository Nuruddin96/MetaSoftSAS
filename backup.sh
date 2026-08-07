#!/bin/bash

DATE=$(date +%F-%H%M)

tar -czf ~/domains/metasoftbd.com/shopsaas-backup-$DATE.tar.gz \
~/domains/metasoftbd.com/apps/shopsaas

echo "Backup completed:"
echo "~/domains/metasoftbd.com/shopsaas-backup-$DATE.tar.gz"
