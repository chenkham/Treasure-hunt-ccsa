"""Offline-only QA of the real HTML print renderer; never accesses the live site."""
from pathlib import Path
import json
import os
import subprocess
import hashlib
import cv2
import fitz
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
folder=ROOT/'runtime/print-qa'
edge=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
decoded={}
jdk=Path(r'C:\Program Files\Microsoft\jdk-21.0.11.10-hotspot\bin')
jar=next((Path.home()/'.gradle/caches/modules-2/files-2.1/com.google.zxing/core/3.5.3').rglob('core-3.5.3.jar'))
subprocess.run([str(jdk/'javac.exe'),'-cp',str(jar),'-d',str(folder),str(ROOT/'php-server/tests/QrRead.java')],check=True)
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=edge,headless=True)
    context=browser.new_context()
    context.route('**/*',lambda route:route.abort())
    page=context.new_page();page.emulate_media(media='print')
    for mode in ['stations','organizer']:
        page.set_content((folder/(mode+'.html')).read_text('utf-8'),wait_until='load')
        page.evaluate('document.fonts.ready')
        geometry=page.evaluate('''() => ({pages:document.querySelectorAll('.print-page').length,cards:document.querySelectorAll('article').length,
          overflow:[...document.querySelectorAll('article')].filter(a=>a.scrollHeight>a.clientHeight+2).length,
          perPage:[...document.querySelectorAll('.print-page')].map(p=>p.querySelectorAll('article').length)})''')
        assert geometry=={'pages':10,'cards':40,'overflow':0,'perPage':[4]*10},geometry
        pdf=folder/(mode+'.pdf');page.pdf(path=str(pdf),prefer_css_page_size=True,print_background=True)
        doc=fitz.open(pdf);assert len(doc)==10,len(doc)
        values=[]
        for index in range(len(doc)):
            # 300 dpi keeps dense signed QR codes readable at their printed size.
            pix=doc[index].get_pixmap(dpi=300);png=folder/f'{mode}-{index+1}.png';pix.save(png)
            image=cv2.imread(str(png));h,w=image.shape[:2]
            for row in range(2):
                for col in range(2):
                    cell=image[int(h*(.03+.47*row)):int(h*(.50+.47*row)),int(w*(.03+.47*col)):int(w*(.50+.47*col))]
                    value,_,_=cv2.QRCodeDetector().detectAndDecode(cell)
                    if not value:
                        crop=folder/'qr-cell.png';cv2.imwrite(str(crop),cell)
                        value=subprocess.check_output([str(jdk/'java.exe'),'-cp',str(folder)+os.pathsep+str(jar),'QrRead',str(crop)],text=True,encoding='utf-8')
                    assert value,(mode,index,row,col)
                    envelope=json.loads(value);assert 'payload' in envelope and 'signature' in envelope
                    values.append(envelope['payload'])
        assert len(set(values))==40
        decoded[mode]=set(values)
        doc.close()
    assert decoded['stations']==decoded['organizer']
    browser.close()
(ROOT/'artifacts/print-qa-checks.json').write_text(json.dumps({'a4_pages':20,'cards_per_page':4,'qr_codes_decoded':80,'overflow':0,'identical_checkpoint_payloads':True,'css_sha256':hashlib.sha256((ROOT/'php-server/dashboard/print.css').read_bytes()).hexdigest(),'renderer_sha256':hashlib.sha256((ROOT/'php-server/app/PrintPack.php').read_bytes()).hexdigest()}),'utf-8')
print('Print QA passed: 10 A4 pages per pack, 4 cards per page, no overflow, all 80 QR codes decoded.')
