const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ headless: true });
    const page = await browser.newPage();
    
    // Catch console logs
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.error('PAGE ERROR:', err));
    
    try {
        await page.goto('http://localhost:3000/login.php', { waitUntil: 'networkidle2' });
        await page.type('#username', 'admin');
        await page.type('#password', 'APTPranoto@2025!');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2' }),
            page.click('button[type="submit"]')
        ]);
        
        console.log("Logged in. Current URL:", page.url());
        
        // Wait for table to load
        await page.waitForSelector('#results-table');
        
        // Check if copyLink is defined
        const typeOfCopyLink = await page.evaluate(() => typeof copyLink);
        console.log("typeof copyLink:", typeOfCopyLink);
        
        const typeOfShowQRModal = await page.evaluate(() => typeof showQRModal);
        console.log("typeof showQRModal:", typeOfShowQRModal);
        
        // Trigger a click on the first "Salin Link" button
        console.log("Clicking Salin Link...");
        await page.evaluate(() => {
            const btn = document.querySelector('a[title="Salin Link"]');
            if (btn) btn.click();
            else console.log("Salin Link button not found");
        });
        
        // Wait a bit
        await new Promise(r => setTimeout(r, 1000));
        
        console.log("Clicking QR Code...");
        await page.evaluate(() => {
            const btn = document.querySelector('a[title="QR Code"]');
            if (btn) btn.click();
            else console.log("QR Code button not found");
        });
        
        // Check if modal is visible
        const qrModalDisplay = await page.evaluate(() => {
            const m = document.getElementById('qr-modal');
            return m ? getComputedStyle(m).display : 'null';
        });
        console.log("QR Modal display:", qrModalDisplay);
        
    } catch (e) {
        console.error("Test Error:", e);
    } finally {
        await browser.close();
    }
})();
