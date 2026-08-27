package main

import (
    "bytes"
    "encoding/base64"
    "encoding/json"
    "encoding/xml"
    "flag"
    "fmt"
    "image"
    "image/draw"
    _ "image/jpeg"
    "image/png"
    "io/ioutil"
    "log"
    "net/http"
    "os"
    "os/exec"
    "strconv"
    "strings"
    "time"

    "golang.org/x/image/font"
    "golang.org/x/image/font/opentype"
    "golang.org/x/image/font/sfnt"
    xdraw "golang.org/x/image/draw"
    "golang.org/x/image/math/fixed"
)

// --- Global Configuration ---
var config Config
var loadedFont *sfnt.Font

// --- Data Structures ---
type PrintRequestPayload struct {
    LogID       int               `json:"log_id"`
    TemplateXML string            `json:"template_xml"`
    Fields      map[string]string `json:"fields"`
}

// --- XML Template Structs ---
type FontDef struct {
    ID   string `xml:"id,attr"`
    Size string `xml:"size,attr"`
}
type Template struct {
    Fonts []FontDef `xml:"fonts>font"`
    Sides []Side    `xml:"sides>side"`
}
type Side struct {
    Name       string      `xml:"name,attr"`
    PrintTypes []PrintType `xml:"print_types>print_type"`
}
type PrintType struct {
    Graphics []Graphic `xml:"graphic"`
    Texts    []Text    `xml:"text"`
}
type Graphic struct {
    Field  string `xml:"field,attr"`
    X      string `xml:"x,attr"`
    Y      string `xml:"y,attr"`
    Width  string `xml:"width,attr"`
    Height string `xml:"height,attr"`
}
type Text struct {
    Field  string `xml:"field,attr"`
    FontID string `xml:"font_id,attr"`
    X      string `xml:"x,attr"`
    Y      string `xml:"y,attr"`
    Alignment string `xml:"alignment,attr"`
    Width     string `xml:"width,attr"` 
}

// --- Core Logic ---

func updatePrintStatusInWordPress(logID int, status string, message string) {
    payload := map[string]interface{}{
        "log_id":         logID,
        "status":         status,
        "status_message": message,
    }
    jsonData, err := json.Marshal(payload)
    if err != nil {
        log.Printf("[LogID: %d] Error marshalling status update JSON: %v", logID, err)
        return
    }
    req, err := http.NewRequest("POST", config.ApiURL, bytes.NewBuffer(jsonData))
    if err != nil {
        log.Printf("[LogID: %d] Error creating API request: %v", logID, err)
        return
    }
    req.Header.Set("Content-Type", "application/json")
    req.Header.Set("X-Internal-API-Key", config.ApiToken)
    client := &http.Client{Timeout: 10 * time.Second}
    resp, err := client.Do(req)
    if err != nil {
        log.Printf("[LogID: %d] Error sending status update to WordPress: %v", logID, err)
        return
    }
    defer resp.Body.Close()
    if resp.StatusCode != http.StatusOK {
        log.Printf("[LogID: %d] WordPress API returned a non-200 status for update: %s", logID, resp.Status)
    } else {
        log.Printf("[LogID: %d] Successfully updated status to '%s' via API.", logID, status)
    }
}

func createImage(payload PrintRequestPayload) (string, error) {
    var t Template
    if err := xml.Unmarshal([]byte(payload.TemplateXML), &t); err != nil {
        return "", fmt.Errorf("could not parse XML template: %v", err)
    }

    fontMap := make(map[string]string)
    for _, fontDef := range t.Fonts {
        fontMap[fontDef.ID] = fontDef.Size
    }

    cardWidth, cardHeight := 640, 1016 // Portrait
    cardCanvas := image.NewRGBA(image.Rect(0, 0, cardWidth, cardHeight))

    for _, side := range t.Sides {
        if side.Name == "front" {
            for _, pt := range side.PrintTypes {
                for _, graphic := range pt.Graphics {
                    base64Data, ok := payload.Fields[graphic.Field]
                    if !ok || base64Data == "" {
                        continue
                    }
                    imgData, err := base64.StdEncoding.DecodeString(base64Data)
                    if err != nil {
                        return "", fmt.Errorf("could not decode graphic '%s': %v", graphic.Field, err)
                    }
                    img, _, err := image.Decode(bytes.NewReader(imgData))
                    if err != nil {
                        return "", fmt.Errorf("could not decode image data for '%s': %v", graphic.Field, err)
                    }
                    x, _ := strconv.Atoi(graphic.X)
                    y, _ := strconv.Atoi(graphic.Y)
                    w, _ := strconv.Atoi(graphic.Width)
                    h, _ := strconv.Atoi(graphic.Height)
                    rect := image.Rect(x, y, x+w, y+h)
                    xdraw.ApproxBiLinear.Scale(cardCanvas, rect, img, img.Bounds(), draw.Over, nil)
                }

                for _, text := range pt.Texts {
                    textValue, ok := payload.Fields[text.Field]
                    if !ok || textValue == "" {
                        continue
                    }
                    sizeStr, ok := fontMap[text.FontID]
                    if !ok {
                        sizeStr = "28"
                    }
                    initialSize, err := strconv.ParseFloat(sizeStr, 64)
                    if err != nil {
                        initialSize = 28.0
                        log.Printf("Warning: Could not parse font size '%s', using default", sizeStr)
                    }
                    x, _ := strconv.Atoi(text.X)
                    y, _ := strconv.Atoi(text.Y)
                    boxWidth, _ := strconv.Atoi(text.Width) // Get box width for centering

                    finalSize := initialSize

                    // Only perform dynamic sizing for firstName and lastName fields
                    if text.Field == "firstName" || text.Field == "lastName" {
log.Printf("DEBUG: Checking field '%s' with text '%s'. BoxWidth: %d, InitialSize: %.1f", text.Field, textValue, boxWidth, initialSize)
                        finalSize = calculateFittingFontSize(textValue, initialSize, boxWidth)
                    }
                    addLabel(cardCanvas, x, y, boxWidth, text.Alignment, textValue, finalSize)
                }
            }
        }
    }

    tmpFile, err := os.CreateTemp("/var/www/html/wp-content/uploads/fsbhoa_print_temp", fmt.Sprintf("printjob-%d-*.png", payload.LogID))
    if err != nil {
        return "", fmt.Errorf("could not create temp file: %v", err)
    }
    defer tmpFile.Close()
    tmpFile.Chmod(0644)

    if err := png.Encode(tmpFile, cardCanvas); err != nil {
        return "", fmt.Errorf("could not encode image to PNG: %v", err)
    }

    return tmpFile.Name(), nil
}

func addLabel(img *image.RGBA, x int, y int, boxWidth int, alignment string, label string, size float64) {
    face, err := opentype.NewFace(loadedFont, &opentype.FaceOptions{
        Size:    size,
        DPI:     72,
        Hinting: font.HintingFull,
    })
    if err != nil {
        log.Printf("Failed to create font face: %v", err)
        return
    }

    d := &font.Drawer{
        Dst:  img,
        Src:  image.Black,
        Face: face,
    }

    // Calculate the starting X-coordinate based on text alignment
    textWidth := d.MeasureString(label).Ceil()
    startX := x
    if alignment == "center" {
        startX = x + (boxWidth-textWidth)/2
    } else if alignment == "right" {
        startX = x + boxWidth - textWidth
    }

    d.Dot = fixed.Point26_6{X: fixed.I(startX), Y: fixed.I(y)}
    d.DrawString(label)
}


func doPrintJob(payload PrintRequestPayload) {
    logID := payload.LogID
    log.Printf("[LogID: %d] Starting print job.", logID)

    imagePath, err := createImage(payload)
    if err != nil {
        errMsg := fmt.Sprintf("Failed to generate print image: %v", err)
        log.Printf("[LogID: %d] %s", logID, errMsg)
        updatePrintStatusInWordPress(logID, "failed_error", errMsg)
        return
    }

    if config.DebugMode {
        log.Printf("[LogID: %d] DEBUG MODE ENABLED. Print job skipped. Image saved at %s", logID, imagePath)
        updatePrintStatusInWordPress(logID, "completed_ok", "Job finished in debug mode (dry run).")
        return
    }

    defer os.Remove(imagePath)
    log.Printf("[LogID: %d] Successfully created temporary print image at %s", logID, imagePath)

    printerName := config.PrinterName
    if printerName == "" {
        errMsg := "Printer name is not configured in zebra_print_service.json"
        log.Printf("[LogID: %d] %s", logID, errMsg)
        updatePrintStatusInWordPress(logID, "failed_error", errMsg)
        return
    }

    cmd := exec.Command("/usr/bin/lp",
        "-d", printerName,
        imagePath,
    )

    output, err := cmd.CombinedOutput()
    if err != nil {
        errMsg := fmt.Sprintf("lp command failed: %v. Output: %s", err, string(output))
        log.Printf("[LogID: %d] %s", logID, errMsg)
        updatePrintStatusInWordPress(logID, "failed_error", errMsg)
        return
    }

    outputStr := string(output)
    parts := strings.Fields(outputStr)
    if len(parts) < 4 || parts[0] != "request" {
        errMsg := fmt.Sprintf("Could not parse request ID from lp output: %s", outputStr)
        log.Printf("[LogID: %d] %s", logID, errMsg)
        updatePrintStatusInWordPress(logID, "failed_error", errMsg)
        return
    }
    requestID := parts[3]
    log.Printf("[LogID: %d] Job submitted to CUPS with request ID: %s", logID, requestID)

    timeout := time.After(2 * time.Minute)
    ticker := time.NewTicker(2 * time.Second)
    defer ticker.Stop()

    for {
        select {
        case <-timeout:
            errMsg := "Print job timed out after 2 minutes."
            log.Printf("[LogID: %d] %s", logID, errMsg)
            updatePrintStatusInWordPress(logID, "failed_error", errMsg)
            return
        case <-ticker.C:
            lpstatCmd := exec.Command("lpstat", "-o")
            lpstatOutput, _ := lpstatCmd.CombinedOutput()
            if !strings.Contains(string(lpstatOutput), requestID) {
                finalMessage := fmt.Sprintf("CUPS job %s completed.", requestID)
                log.Printf("[LogID: %d] %s", logID, finalMessage)
                updatePrintStatusInWordPress(logID, "completed_ok", finalMessage)
                return
            }
        }
    }
}

func printCardHandler(w http.ResponseWriter, r *http.Request) {
    if r.Method != http.MethodPost {
        http.Error(w, "Only POST method is accepted", http.StatusMethodNotAllowed)
        return
    }
    var payload PrintRequestPayload
    if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
        http.Error(w, "Error parsing JSON payload", http.StatusBadRequest)
        return
    }
    log.Printf("Received print request for LogID: %d", payload.LogID)
    updatePrintStatusInWordPress(payload.LogID, "printing", "Job received by Go service.")
    go doPrintJob(payload)
    w.WriteHeader(http.StatusAccepted)
    fmt.Fprintf(w, "Job for LogID %d accepted and is being processed.", payload.LogID)
}

func main() {
    fontBytes, err := ioutil.ReadFile("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
    if err != nil {
        log.Fatalf("FATAL: Could not read font file: %v", err)
    }
    loadedFont, err = sfnt.Parse(fontBytes)
    if err != nil {
        log.Fatalf("FATAL: Could not parse font file: %v", err)
    }

    configFile := flag.String("config", "/var/lib/fsbhoa/zebra_print_service.json", "Path to the JSON configuration file.")
    flag.Parse()
    config, err = LoadConfig(*configFile)
    if err != nil {
        log.Fatalf("FATAL: Could not load config file '%s': %v", *configFile, err)
    }
    log.Printf("Configuration loaded successfully from %s", *configFile)

    http.HandleFunc("/print_card", printCardHandler)
    http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
        fmt.Fprintf(w, "FSBHOA Go Printer Service is running!")
    })
    addr := fmt.Sprintf(":%d", config.Port)
    log.Printf("Starting Zebra Print Service on http://127.0.0.1%s...", addr)
    if err := http.ListenAndServe(addr, nil); err != nil {
        log.Fatal("ListenAndServe: ", err)
    }
}

// calculateFittingFontSize determines the largest font size that allows the label to fit within the boxWidth.
func calculateFittingFontSize(label string, initialSize float64, boxWidth int) float64 {
    size := initialSize
    
    // Iteratively reduce the font size by 10% until the text fits.
    for size > 8.0 { // Don't allow the font to become unreadably small
        face, err := opentype.NewFace(loadedFont, &opentype.FaceOptions{
            Size:    size,
            DPI:     72,
            Hinting: font.HintingFull,
        })
        if err != nil {
            log.Printf("Warning: Failed to create font face for size check: %v", err)
            return size // Return current size on error
        }
        
        textWidth := font.MeasureString(face, label).Ceil()
        log.Printf("DEBUG: > Trying size %.1f, Measured width: %d", size, textWidth)
        
        if textWidth <= boxWidth {
            // It fits! Return this size.
            return size
        }
        
        // It doesn't fit, reduce the size and try again.
        size *= 0.90
    }
    
    return size // Return the smallest calculated size if it never fits
}

