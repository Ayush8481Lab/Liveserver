import express from 'express';
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import crypto from 'crypto';

// Activate the stealth plugin
puppeteer.use(StealthPlugin());

const app = express();
const PORT = process.env.PORT || 3000;

app.get('/api/cap', async (req, res) => {
  const url = req.query.url;
  const wait = req.query.wait ? parseInt(req.query.wait) : 5;

  if (!url) {
    return res.status(400).json({ error: "Please provide a URL." });
  }

  let browser = null;
  try {
    // Launch standard puppeteer (Docker will provide the executable path)
    browser = await puppeteer.launch({
      headless: true,
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-blink-features=AutomationControlled',
        '--disable-dev-shm-usage' // Crucial for Docker/Render environments
      ]
    });

    const page = await browser.newPage();
    
    // 1. SET REAL USER AGENT
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36');

    // 2. STEALTH: Remove webdriver (StealthPlugin handles this, but keeping your manual override just in case)
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => false });
    });

    const networkLogs = [];
    const generateHash = (data) => crypto.createHash('sha256').update(data).digest('hex');

    // Deep Network Listener
    page.on('response', async (response) => {
      try {
        const request = response.request();
        
        const logEntry = {
          url: response.url(),
          method: request.method(),
          type: request.resourceType(),
          status: response.status(),
          requestHeaders: request.headers(), 
          responseHeaders: response.headers(), 
          sha256Hash: "n/a"
        };

        try { logEntry.hash = new URL(response.url()).hash; } catch (e) { logEntry.hash = ""; }

        if (response.status() < 300 || response.status() >= 400) {
          if (!['image', 'media', 'font'].includes(request.resourceType())) {
            const buffer = await response.buffer().catch(() => null);
            if (buffer) {
              logEntry.sha256Hash = generateHash(buffer);
            }
          }
        }
        networkLogs.push(logEntry);
      } catch (err) {
        // Silently skip
      }
    });

    // 3. BYPASS CHALLENGE: Navigate and wait
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });

    // Extra wait for challenges
    await new Promise(resolve => setTimeout(resolve, wait * 1000));

    // Final Capture
    const finalUrl = page.url();
    const htmlContent = await page.content();
    const pageSha256 = generateHash(htmlContent);

    let finalUrlFragment = "";
    try { finalUrlFragment = new URL(finalUrl).hash; } catch (e) { finalUrlFragment = ""; }

    await browser.close();

    return res.status(200).json({
      target_url: url,
      final_url: finalUrl,
      url_fragment: finalUrlFragment,
      page_sha256Hash: pageSha256,
      waited_seconds: wait,
      total_requests: networkLogs.length,
      logs: networkLogs
    });

  } catch (error) {
    if (browser) await browser.close();
    return res.status(500).json({ 
      error: "Bypass failed or timeout", 
      details: error.message
    });
  }
});

app.listen(PORT, () => {
  console.log(`Server listening on port ${PORT}`);
});
