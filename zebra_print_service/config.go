package main

import (
    "encoding/json"
    "io"
    "os"
)

// Config defines the structure of our JSON configuration file.
type Config struct {
    Port     int    `json:"port"`
    ApiURL   string `json:"api_url"`
    ApiToken string `json:"api_token"`
    PrinterName string `json:"printer_name"`
    DebugMode   bool   `json:"debug_mode"`
}

// LoadConfig reads the configuration from the given file path.
func LoadConfig(path string) (Config, error) {
    var config Config

    jsonFile, err := os.Open(path)
    if err != nil {
        return config, err
    }
    defer jsonFile.Close()

    byteValue, err := io.ReadAll(jsonFile)
    if err != nil {
        return config, err
    }

    err = json.Unmarshal(byteValue, &config)
    return config, err
}

