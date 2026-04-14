#!/bin/bash

# Check if base path argument is provided
if [ $# -eq 0 ]; then
    echo "Error: Base path is required"
    echo "Usage: $0 <base-path>"
    echo "Example: $0 revtek-api"
    exit 1
fi

BASE_PATH="$1"

# Check if the target directory exists
if [ ! -d "$BASE_PATH/vendor/rev/laravel-amqp" ]; then
    echo "Error: Target directory '../$BASE_PATH/vendor/rev/laravel-amqp' does not exist"
    exit 1
fi

# Copy files
cp -r * "$BASE_PATH/vendor/rev/laravel-amqp/"

echo "Files copied successfully to $BASE_PATH/vendor/rev/laravel-amqp/"