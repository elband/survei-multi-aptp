// ================================================================
// EDITOR SURVEI - Google Forms Style + AI Generator
// ================================================================
const stepsContainer = document.getElementById('steps-editor-container');
const TYPES_WITH_OPTIONS = ['radio', 'checkbox', 'select', 'day-selector'];

let editorConfig = JSON.parse(JSON.stringify(typeof surveyConfig !== 'undefined' ? surveyConfig : []));

// ---- Custom Confirm Modal ----
const customConfirm = (message, onConfirm) => {
    let overlay = document.getElementById('custom-confirm-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'custom-confirm-overlay';
        overlay.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10000;display:none;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s;`;
        overlay.innerHTML = `
            <div style="background:#fff;border-radius:16px;padding:28px;width:min(400px, 90vw);box-shadow:0 10px 40px rgba(0,0,0,0.2);transform:scale(0.9);transition:transform 0.2s;text-align:center;">
                <div style="font-size:3rem;color:#fa5252;margin-bottom:16px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <h3 style="margin:0 0 12px;font-size:1.3rem;color:#2b2b2b;">Konfirmasi Hapus</h3>
                <p id="custom-confirm-msg" style="margin:0 0 24px;color:#666;font-size:0.95rem;line-height:1.4;"></p>
                <div style="display:flex;gap:12px;justify-content:center;">
                    <button id="custom-confirm-cancel" class="btn btn-outline" style="flex:1;padding:10px;font-size:0.9rem;">Batal</button>
                    <button id="custom-confirm-ok" class="btn btn-primary" style="flex:1;padding:10px;font-size:0.9rem;background:#fa5252;border-color:#fa5252;">Ya, Hapus</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        
        overlay.querySelector('#custom-confirm-cancel').addEventListener('click', () => {
            overlay.style.opacity = '0';
            overlay.firstElementChild.style.transform = 'scale(0.9)';
            setTimeout(() => overlay.style.display = 'none', 200);
        });
    }
    
    document.getElementById('custom-confirm-msg').innerText = message;
    overlay.style.display = 'flex';
    setTimeout(() => {
        overlay.style.opacity = '1';
        overlay.firstElementChild.style.transform = 'scale(1)';
    }, 10);
    
    const btnOk = document.getElementById('custom-confirm-ok');
    btnOk.onclick = () => {
        overlay.style.opacity = '0';
        overlay.firstElementChild.style.transform = 'scale(0.9)';
        setTimeout(() => overlay.style.display = 'none', 200);
        onConfirm();
    };
};

// ---- Toolbar Google Forms Style ----
const toolbar = document.createElement('div');
toolbar.id = 'gf-toolbar';
toolbar.innerHTML = `
    <div class="gf-toolbar-title"><i class="fa-solid fa-wand-magic-sparkles"></i><span>Alat</span></div>
    <button class="gf-tool" id="tb-add-q" title="Tambah Pertanyaan"><i class="fa-solid fa-circle-plus"></i><span>Pertanyaan</span></button>
    <button class="gf-tool" id="tb-add-sec" title="Tambah Bagian"><i class="fa-solid fa-layer-group"></i><span>Bagian</span></button>
    <hr style="border:none;border-top:1px solid rgba(255,255,255,0.2);margin:6px 0">
    <button class="gf-tool gf-ai-btn" id="tb-ai-gen" title="Generate dengan AI"><i class="fa-solid fa-robot"></i><span>AI Generate</span></button>
`;
document.getElementById('tab-editor').appendChild(toolbar);

// Toolbar CSS
const styleEl = document.createElement('style');
styleEl.textContent = `
#gf-toolbar {
    position: fixed; right: 20px; top: 50%; transform: translateY(-50%);
    background: linear-gradient(135deg,#1971c2,#1864ab);
    border-radius: 16px; padding: 12px 8px; display: flex; flex-direction: column;
    gap: 4px; box-shadow: 0 8px 32px rgba(25,113,194,0.4); z-index: 100; min-width: 70px;
}
.gf-toolbar-title { color: rgba(255,255,255,0.7); font-size:0.65rem; text-align:center; padding:4px 0 6px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; display:flex; flex-direction:column; align-items:center; gap:3px; }
.gf-tool {
    background: rgba(255,255,255,0.15); border: none; border-radius: 10px;
    color: #fff; cursor: pointer; padding: 10px 6px; display: flex; flex-direction: column;
    align-items: center; gap: 4px; font-size: 0.7rem; font-weight: 600;
    transition: background 0.2s; width: 100%;
}
.gf-tool:hover { background: rgba(255,255,255,0.28); }
.gf-tool i { font-size: 1.1rem; }
.gf-ai-btn { background: linear-gradient(135deg,#7048e8,#5f3dc4) !important; }
.gf-ai-btn:hover { background: linear-gradient(135deg,#5f3dc4,#4c2bb5) !important; }

/* AI Modal */
#ai-modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5);
    z-index:9999; align-items:center; justify-content:center;
}
#ai-modal-overlay.open { display:flex; }
#ai-modal {
    background:#fff; border-radius:20px; padding:28px; width:min(560px, 95vw);
    box-shadow:0 20px 60px rgba(0,0,0,0.25); max-height:85vh; overflow-y:auto;
}
#ai-modal h3 { margin:0 0 6px; font-size:1.1rem; color:#1971c2; }
#ai-modal p { margin:0 0 16px; font-size:0.85rem; color:#888; }
#ai-topic-input {
    width:100%; box-sizing:border-box; padding:10px 14px; border:1.5px solid #dee2e6;
    border-radius:10px; font-size:0.95rem; font-family:inherit; resize:vertical;
    min-height:80px;
}
#ai-section-select { width:100%; padding:9px 12px; border:1.5px solid #dee2e6; border-radius:10px; font-family:inherit; margin-top:8px; }
.ai-modal-actions { display:flex; gap:10px; margin-top:16px; justify-content:flex-end; }
.ai-result-list { margin-top:16px; display:flex; flex-direction:column; gap:8px; }
.ai-q-item {
    border:1.5px solid #dbe4ff; border-radius:10px; padding:12px 14px;
    display:flex; justify-content:space-between; align-items:flex-start; gap:10px;
    background:#f8f9ff; cursor:pointer; transition:background 0.15s;
}
.ai-q-item:hover { background:#dbe4ff; }
.ai-q-item .ai-q-label { font-weight:600; font-size:0.9rem; color:#1a1a2e; }
.ai-q-item .ai-q-type { font-size:0.75rem; color:#1971c2; background:#dbe4ff; padding:2px 8px; border-radius:20px; white-space:nowrap; }
#ai-add-all-btn { display:none; }
@media(max-width:768px){ #gf-toolbar { right:10px; min-width:58px; } .gf-tool span, .gf-toolbar-title span { display:none; } }
`;
document.head.appendChild(styleEl);

// AI Modal HTML
const aiOverlay = document.createElement('div');
aiOverlay.id = 'ai-modal-overlay';
aiOverlay.innerHTML = `
<div id="ai-modal">
    <h3><i class="fa-solid fa-robot"></i> AI Generate Pertanyaan</h3>
    <p>Masukkan topik survei, AI akan membuatkan pertanyaan secara otomatis.</p>
    <textarea id="ai-topic-input" placeholder="Contoh: kepuasan pelayanan bandara, kenyamanan lounge, dll..."></textarea>
    <select id="ai-section-select"><option value="">-- Pilih bagian tujuan --</option></select>
    <div class="ai-modal-actions">
        <button class="btn" id="ai-cancel-btn" style="background:#f1f3f5;color:#444;border:none;padding:9px 18px;border-radius:10px;cursor:pointer">Batal</button>
        <button class="btn btn-primary" id="ai-generate-btn" style="padding:9px 18px;border-radius:10px;border:none;cursor:pointer;font-weight:600"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
    </div>
    <div class="ai-result-list" id="ai-result-list"></div>
    <div class="ai-modal-actions" id="ai-add-all-wrap" style="display:none">
        <button class="btn btn-primary" id="ai-add-all-btn" style="padding:9px 18px;border-radius:10px;border:none;cursor:pointer;font-weight:600"><i class="fa-solid fa-plus"></i> Tambahkan Semua ke Bagian</button>
    </div>
</div>`;
document.body.appendChild(aiOverlay);

// ---- Core Editor Functions ----
const getStepIdx = (stepDiv) => parseInt(stepDiv.dataset.stepId);

const syncAllFromDOM = () => {
    stepsContainer.querySelectorAll('.step-editor').forEach(stepDiv => {
        const id = stepDiv.dataset.stepId;
        const cfg = editorConfig.find(s => s.id === id);
        if (!cfg) return;
        cfg.title = stepDiv.querySelector('.step-title-input').value;
        cfg.description = stepDiv.querySelector('.step-desc-input').value;
        const questions = [];
        stepDiv.querySelectorAll('.question-card').forEach(card => {
            const type = card.querySelector('.q-type-select').value;
            const label = card.querySelector('.q-label').value;
            const name = card.querySelector('.q-name').value;
            const required = card.querySelector('.q-required').checked;
            const hasOther = card.querySelector('.q-has-other').checked;
            const q = { type, name, label, required, hasOther };
            if (TYPES_WITH_OPTIONS.includes(type)) {
                q.options = [];
                card.querySelectorAll('.option-row input[type="text"]').forEach(inp => {
                    const txt = inp.value;
                    if (txt) q.options.push({ value: txt, label: txt });
                });
            }
            questions.push(q);
        });
        cfg._flatQ = questions;
    });
};

const renderEditor = () => {
    stepsContainer.innerHTML = '';
    editorConfig.forEach(step => renderStep(step));
};

const renderStep = (step) => {
    if (!step.id) step.id = 'step-' + Date.now() + Math.random();
    const stepDiv = document.createElement('div');
    stepDiv.className = 'step-editor';
    stepDiv.dataset.stepId = step.id;

    stepDiv.innerHTML = `
        <div class="step-header">
            <div class="step-num">${editorConfig.indexOf(step) + 1}</div>
            <div class="step-header-fields">
                <input type="text" class="step-title-input" placeholder="Judul Bagian" value="${step.title || ''}">
                <input type="text" class="step-desc-input" placeholder="Deskripsi (opsional)" value="${step.description || ''}" style="flex:1.5">
            </div>
            <div class="step-header-actions">
                <button class="btn-icon-white btn-del-step" title="Hapus Bagian"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <div class="step-body">
            <div class="questions-list"></div>
            <button class="btn-add-question"><i class="fa-solid fa-plus"></i> Tambah Pertanyaan</button>
        </div>
    `;

    // Delete step — uses step.id NOT sIdx (fix bug!)
    stepDiv.querySelector('.btn-del-step').addEventListener('click', () => {
        if (editorConfig.length <= 1) { showToast('Minimal harus ada 1 bagian.', 'error'); return; }
        customConfirm(`Hapus bagian "${step.title || 'ini'}"?`, () => {
            syncAllFromDOM();
            const idx = editorConfig.findIndex(s => s.id === step.id);
            if (idx !== -1) editorConfig.splice(idx, 1);
            renderEditor();
        });
    });

    // Populate _flatQ only if it doesn't exist (preserve state!)
    if (!step._flatQ) {
        const flat = [];
        (step.questions || []).forEach(q => {
            if (q.type === 'row') q.questions.forEach(sq => flat.push(sq));
            else flat.push(q);
        });
        step._flatQ = flat;
    }

    const ql = stepDiv.querySelector('.questions-list');

    // Drag & Drop
    ql.addEventListener('dragover', e => {
        e.preventDefault();
        const dragging = document.querySelector('.dragging');
        if (!dragging) return;
        const after = getDragAfter(ql, e.clientY);
        after ? ql.insertBefore(dragging, after) : ql.appendChild(dragging);
    });
    ql.addEventListener('drop', e => {
        e.preventDefault();
        syncAllFromDOM();
        ql.innerHTML = '';
        const cfg = editorConfig.find(s => s.id === step.id);
        cfg._flatQ.forEach((q, i) => renderQuestion(q, i, step.id, ql));
    });

    step._flatQ.forEach((q, i) => renderQuestion(q, i, step.id, ql));

    // Add question (Local button at the bottom of section -> always append to end)
    stepDiv.querySelector('.btn-add-question').addEventListener('click', () => {
        syncAllFromDOM();
        const newQ = { type: 'text', name: '', label: '', required: false };
        const cfg = editorConfig.find(s => s.id === step.id);
        
        cfg._flatQ.push(newQ);
        activeQIndex = cfg._flatQ.length - 1;
        activeStepId = step.id; // force active
        
        ql.innerHTML = '';
        cfg._flatQ.forEach((q, i) => renderQuestion(q, i, step.id, ql));
        
        setTimeout(() => {
            const labels = ql.querySelectorAll('.q-label');
            if (labels.length && labels[activeQIndex]) {
                const card = labels[activeQIndex].closest('.question-card');
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.style.transition = 'box-shadow 0.3s';
                    card.style.boxShadow = '0 0 0 3px rgba(25,113,194,0.5)';
                    setTimeout(() => card.style.boxShadow = '', 1500);
                }
                labels[activeQIndex].focus();
            }
        }, 50);
    });

    stepsContainer.appendChild(stepDiv);
};

const getDragAfter = (container, y) => {
    const items = [...container.querySelectorAll('.question-card:not(.dragging)')];
    return items.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
};

const renderQuestion = (q, qIdx, stepId, container) => {
    const tpl = document.getElementById('question-tpl');
    const clone = tpl.content.cloneNode(true);
    const card = clone.querySelector('.question-card');
    card.dataset.qIdx = qIdx;
    card.dataset.stepId = stepId;
    card.draggable = true;
    card.addEventListener('dragstart', function (e) {
        if (document.activeElement) document.activeElement.blur();
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(() => this.classList.add('dragging'), 0);
    });
    card.addEventListener('dragend', function () { this.classList.remove('dragging'); });

    const typeSelect = card.querySelector('.q-type-select');
    typeSelect.value = q.type || 'text';
    card.querySelector('.q-label').value = q.label || '';
    card.querySelector('.q-name').value = q.name || '';
    card.querySelector('.q-required').checked = !!q.required;
    card.querySelector('.q-has-other').checked = !!q.hasOther;

    const optSec = card.querySelector('.q-options-section');
    const optList = card.querySelector('.q-options-list');

    const showOpts = type => { optSec.style.display = TYPES_WITH_OPTIONS.includes(type) ? 'block' : 'none'; };
    showOpts(q.type);

    if (q.options && q.options.length) {
        q.options.forEach(opt => addOptionRow(optList, opt.label || opt.value || ''));
    } else if (TYPES_WITH_OPTIONS.includes(q.type)) {
        addOptionRow(optList, '');
    }

    typeSelect.addEventListener('change', e => {
        showOpts(e.target.value);
        if (TYPES_WITH_OPTIONS.includes(e.target.value) && optList.children.length === 0) addOptionRow(optList, '');
    });
    card.querySelector('.btn-add-option').addEventListener('click', () => addOptionRow(optList, ''));

    card.querySelector('.btn-delete-question').addEventListener('click', () => {
        customConfirm('Hapus pertanyaan ini?', () => {
            syncAllFromDOM();
            const cfg = editorConfig.find(s => s.id === stepId);
            if (cfg && cfg._flatQ) {
                // Find current index after sync
                const qCards = [...stepsContainer.querySelector(`[data-step-id="${stepId}"]`).querySelectorAll('.question-card')];
                const realIdx = qCards.indexOf(card);
                if (realIdx !== -1) {
                    cfg._flatQ.splice(realIdx, 1);
                } else {
                    cfg._flatQ.splice(qIdx, 1); // fallback
                }
            }
            renderEditor();
        });
    });

    container.appendChild(clone);
};

const addOptionRow = (list, value) => {
    const row = document.createElement('div');
    row.className = 'option-row';
    row.innerHTML = `
        <span style="color:var(--text-muted);font-size:0.8rem;min-width:16px">•</span>
        <input type="text" placeholder="Teks opsi..." value="${value}">
        <button class="btn-del-option" title="Hapus opsi"><i class="fa-solid fa-minus"></i></button>
    `;
    row.querySelector('.btn-del-option').addEventListener('click', () => row.remove());
    list.appendChild(row);
};

let activeStepId = null;
let activeQIndex = null;

// Track active step when focused or clicked
document.addEventListener('click', (e) => {
    const stepDiv = e.target.closest('.step-editor');
    if (stepDiv) {
        activeStepId = stepDiv.dataset.stepId;
    }
    const qCard = e.target.closest('.question-card');
    if (qCard) {
        activeQIndex = parseInt(qCard.dataset.qIdx || 0);
    }
});

// ---- Toolbar Events ----
const handleAddQuestion = () => {
    syncAllFromDOM();
    if (editorConfig.length === 0) return;
    
    // Find target step
    let targetStep = editorConfig.find(s => s.id === activeStepId);
    if (!targetStep) targetStep = editorConfig[editorConfig.length - 1];
    
    const newQ = { type: 'text', name: '', label: '', required: false };
    
    // Insert after active question or at the end
    if (activeQIndex !== null && !isNaN(activeQIndex) && activeQIndex >= 0 && activeQIndex < targetStep._flatQ.length) {
        targetStep._flatQ.splice(activeQIndex + 1, 0, newQ);
        activeQIndex++; // update activeQIndex so subsequent clicks keep adding downwards
    } else {
        targetStep._flatQ.push(newQ);
        activeQIndex = targetStep._flatQ.length - 1;
    }
    activeStepId = targetStep.id;
    
    renderEditor(); // re-render to show the new question
    setTimeout(() => {
        const stepDiv = stepsContainer.querySelector(`[data-step-id="${targetStep.id}"]`);
        if (stepDiv) {
            const labels = stepDiv.querySelectorAll('.q-label');
            if (labels.length && labels[activeQIndex]) {
                const card = labels[activeQIndex].closest('.question-card');
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.style.transition = 'box-shadow 0.3s';
                    card.style.boxShadow = '0 0 0 3px rgba(25,113,194,0.5)';
                    setTimeout(() => card.style.boxShadow = '', 1500);
                }
                labels[activeQIndex].focus();
            }
        }
    }, 50);
};

const handleAddSection = () => {
    syncAllFromDOM();
    const newStep = { id: 'step-' + Date.now(), title: `Bagian ${editorConfig.length + 1}`, icon: 'fa-list', description: '', questions: [], _flatQ: [] };
    
    let targetIdx = editorConfig.findIndex(s => s.id === activeStepId);
    if (targetIdx !== -1) {
        editorConfig.splice(targetIdx + 1, 0, newStep);
    } else {
        editorConfig.push(newStep);
    }
    
    activeStepId = newStep.id;
    activeQIndex = null;
    
    renderEditor();
    
    setTimeout(() => {
        const stepDiv = stepsContainer.querySelector(`[data-step-id="${newStep.id}"]`);
        if (stepDiv) {
            stepDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const titleInput = stepDiv.querySelector('.step-title-input');
            if (titleInput) titleInput.focus();
        }
    }, 50);
};

console.log("Binding toolbar buttons...");
const btnAddQ = document.getElementById('tb-add-q');
const btnAddSec = document.getElementById('tb-add-sec');
if (btnAddQ) {
    btnAddQ.onclick = (e) => { e.preventDefault(); console.log('tb-add-q clicked!'); handleAddQuestion(); };
} else {
    console.error("tb-add-q not found!");
}
if (btnAddSec) {
    btnAddSec.onclick = (e) => { e.preventDefault(); console.log('tb-add-sec clicked!'); handleAddSection(); };
} else {
    console.error("tb-add-sec not found!");
}

const btnAddStep = document.getElementById('btn-add-step');
if (btnAddStep) btnAddStep.addEventListener('click', handleAddSection);

// ---- Save Config ----
document.getElementById('btn-save-config').addEventListener('click', async () => {
    syncAllFromDOM();
    const finalConfig = editorConfig.map((step, sIdx) => {
        const questions = (step._flatQ || []).map(q => {
            // Auto-generate name if empty
            if (!q.name && q.label) {
                q.name = q.label.toLowerCase().replace(/[^a-z0-9]/g, '_').substring(0, 20);
                if (!q.name) q.name = 'q_' + Math.random().toString(36).substr(2, 5);
            }
            return q;
        }).filter(q => q.label); // Tetap simpan selama ada labelnya

        return {
            id: step.id || `step-${sIdx + 1}`,
            title: step.title || '',
            icon: step.icon || 'fa-list',
            description: step.description || '',
            questions: questions
        };
    });

    const btn = document.getElementById('btn-save-config');
    const sid = btn.dataset.surveyId || surveyId;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
    btn.disabled = true;
    
    // Ambil judul survei dari input field khusus (jika ada), jika tidak fallback ke nama Bagian 1
    const titleInput = document.getElementById('survey-main-title');
    const surveyTitle = titleInput ? titleInput.value.trim() : (finalConfig[0] ? finalConfig[0].title : 'Survei');

    try {
        const res = await fetch('admin.php?id=' + sid, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams({
                action: 'save_config',
                config_json: JSON.stringify(finalConfig),
                title: surveyTitle,
                csrf: typeof csrfToken !== 'undefined' ? csrfToken : ''
            })
        });

        // Tangani jika session expired dan diredirect ke halaman login
        if (res.redirected && res.url.includes('login.php')) {
            throw new Error('Sesi Anda telah habis. Silakan muat ulang halaman dan login kembali.');
        }

        let data = null;
        try { 
            const text = await res.text();
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error("Respons server bukan JSON:", text);
                throw new Error("Server mengembalikan respons tidak valid. Mungkin sesi habis atau ada error di server.");
            }
        } catch (e) {
            throw e;
        }

        if (data && data.success) {
            showToast('✅ Berhasil disimpan!', 'success');
            editorConfig = finalConfig;
        } else {
            throw new Error(data ? (data.message || 'Gagal menyimpan') : 'Gagal menyimpan ke server');
        }
    } catch (err) {
        showToast('❌ Gagal: ' + err.message, 'error');
    } finally {
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan';
        btn.disabled = false;
    }
});

// ---- AI Generate ----
document.getElementById('tb-ai-gen').addEventListener('click', () => {
    const sel = document.getElementById('ai-section-select');
    sel.innerHTML = '<option value="">-- Pilih bagian tujuan --</option>';
    editorConfig.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.title || s.id;
        sel.appendChild(opt);
    });
    document.getElementById('ai-result-list').innerHTML = '';
    document.getElementById('ai-add-all-wrap').style.display = 'none';
    document.getElementById('ai-modal-overlay').classList.add('open');
});

document.getElementById('ai-cancel-btn').addEventListener('click', () => {
    document.getElementById('ai-modal-overlay').classList.remove('open');
});

document.getElementById('ai-modal-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('ai-modal-overlay'))
        document.getElementById('ai-modal-overlay').classList.remove('open');
});

let aiGeneratedQuestions = [];

document.getElementById('ai-generate-btn').addEventListener('click', async () => {
    const topic = document.getElementById('ai-topic-input').value.trim();
    if (!topic) { alert('Masukkan topik survei!'); return; }

    const btn = document.getElementById('ai-generate-btn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses...';
    btn.disabled = true;

    const prompt = `Buatkan 5 pertanyaan survei tentang "${topic}" untuk bandara. 
Jawab HANYA dengan JSON array berikut formatnya:
[{"label":"Pertanyaan?","name":"nama_variable","type":"radio","options":[{"value":"Sangat Baik","label":"Sangat Baik"},{"value":"Baik","label":"Baik"},{"value":"Cukup","label":"Cukup"},{"value":"Kurang","label":"Kurang"}]},...]
Type yang tersedia: text, radio, checkbox, select, textarea.
Untuk type text dan textarea, jangan sertakan options. Untuk radio/checkbox/select, sertakan options yang relevan.`;

    try {
        const res = await fetch('ai_generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'topic=' + encodeURIComponent(topic)
        });
        
        let rawText = await res.text();
        // Bersihkan injeksi script dari hosting gratis (InfinityFree tracker)
        rawText = rawText.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '').trim();
        // Hapus peringatan error PHP yang mungkin muncul di awal
        if (rawText.includes('{')) {
            rawText = rawText.substring(rawText.indexOf('{'));
        }
        
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            throw new Error("Gagal membaca respons dari server. Respons: " + rawText.substring(0, 50));
        }
        
        if (!data.success) {
            throw new Error(data.message || 'Gagal generate dari server');
        }
        
        aiGeneratedQuestions = data.questions;

        const resultList = document.getElementById('ai-result-list');
        resultList.innerHTML = '';
        aiGeneratedQuestions.forEach((q, i) => {
            const item = document.createElement('div');
            item.className = 'ai-q-item';
            item.innerHTML = `
                <div>
                    <div class="ai-q-label">${i + 1}. ${q.label}</div>
                    <div style="font-size:0.8rem;color:#888;margin-top:3px">name: <code>${q.name}</code></div>
                </div>
                <span class="ai-q-type">${q.type}</span>
            `;
            item.addEventListener('click', () => addSingleAIQuestion(q));
            resultList.appendChild(item);
        });
        document.getElementById('ai-add-all-wrap').style.display = 'flex';
    } catch (err) {
        document.getElementById('ai-result-list').innerHTML = `
            <div style="color:#e03131;padding:12px;background:#fff5f5;border-radius:10px;font-size:0.88rem">
                <strong>❌ Gagal generate:</strong> ${err.message}<br><br>
                <strong>Catatan:</strong> Pastikan GEMINI_API_KEY sudah diset di file <code>.env</code>.
            </div>`;
    } finally {
        btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate';
        btn.disabled = false;
    }
});

const addSingleAIQuestion = (q) => {
    const targetId = document.getElementById('ai-section-select').value;
    const cfg = targetId ? editorConfig.find(s => s.id === targetId) : editorConfig[editorConfig.length - 1];
    if (!cfg) { alert('Pilih bagian tujuan!'); return; }
    if (!cfg._flatQ) cfg._flatQ = [];
    cfg._flatQ.push({ ...q, required: false });
    const stepDiv = stepsContainer.querySelector(`[data-step-id="${cfg.id}"]`);
    const ql = stepDiv.querySelector('.questions-list');
    renderQuestion(q, cfg._flatQ.length - 1, cfg.id, ql);
    showToast(`✅ Pertanyaan "${q.label.substring(0, 30)}..." ditambahkan!`, 'success');
};

document.getElementById('ai-add-all-btn').addEventListener('click', () => {
    aiGeneratedQuestions.forEach(q => addSingleAIQuestion(q));
    document.getElementById('ai-modal-overlay').classList.remove('open');
    showToast(`✅ ${aiGeneratedQuestions.length} pertanyaan ditambahkan!`, 'success');
});

// ---- Toast moved to global scope in admin.php ----

// ---- Init ----
renderEditor();
