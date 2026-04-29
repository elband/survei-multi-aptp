document.addEventListener('DOMContentLoaded', () => {
    // Auth Guard
    const token = localStorage.getItem('admin_token');
    if (!token) { window.location.href = 'login.html'; return; }

    // Tab Navigation
    const navLinks = document.querySelectorAll('nav a');
    const tabContents = document.querySelectorAll('.tab-content');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const tab = link.getAttribute('data-tab');
            navLinks.forEach(l => l.classList.remove('active'));
            tabContents.forEach(t => t.classList.remove('active'));
            link.classList.add('active');
            document.getElementById(`tab-${tab}`).classList.add('active');
        });
    });

    document.getElementById('btn-logout').addEventListener('click', () => {
        localStorage.removeItem('admin_token');
        window.location.href = 'login.html';
    });

    // ================================================================
    // TAB 1: HASIL SURVEI
    // ================================================================
    const questionLabelMap = buildLabelMap();

    function buildLabelMap() {
        const map = {};
        if (typeof surveyConfig === 'undefined') return map;
        const walk = (qs) => qs.forEach(q => {
            if (q.type === 'row') walk(q.questions);
            else if (q.name) map[q.name] = q.label;
        });
        surveyConfig.forEach(step => walk(step.questions));
        return map;
    }

    // State for pagination & search
    let currentPage = 1;
    let currentSearch = '';
    let totalResults = 0;
    const PAGE_LIMIT = 25;

    const loadResults = async (page = 1, search = '') => {
        const tbody = document.getElementById('results-body');
        tbody.innerHTML = '<tr><td colspan="8" class="loading"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>';
        currentPage = page;
        currentSearch = search;

        try {
            const params = new URLSearchParams({ page, limit: PAGE_LIMIT, search });
            const res = await fetch(`/api/results?${params}`, { headers: { 'Authorization': token } });
            const data = await res.json();
            if (!data.success) throw new Error(data.message);

            totalResults = data.total;
            window._surveyResults = data.data;

            // Update stats from dedicated endpoint
            loadStats();
            renderPagination(data.total, page);

            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="loading">Belum ada data survei yang masuk.</td></tr>';
                return;
            }

            tbody.innerHTML = '';
            const startNum = (page - 1) * PAGE_LIMIT;
            data.data.forEach((row, i) => {
                const tr = document.createElement('tr');
                const ts = row.submitted_at
                    ? new Date(row.submitted_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
                    : '-';
                tr.innerHTML = `
                    <td style="color:var(--text-muted);font-size:.82rem">${startNum + i + 1}</td>
                    <td style="font-size:.85rem;color:var(--text-muted)">${ts}</td>
                    <td><strong>${row.nama || '-'}</strong></td>
                    <td><span class="badge" style="background:#eef3fc;color:#1971c2">${row.usia || '-'}</span></td>
                    <td style="font-size:.88rem">${row.domisili || '-'}</td>
                    <td style="font-size:.88rem">${row.pekerjaan || '-'}</td>
                    <td>
                        <button class="btn btn-detail" onclick="showDetail(${i})"><i class="fa-solid fa-eye"></i> Detail</button>
                    </td>
                    <td>
                        <button class="btn btn-icon" style="color:#e03131" onclick="deleteRow(${row.id}, this)" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="8" class="loading" style="color:var(--danger)"><i class="fa-solid fa-circle-exclamation"></i> Error: ${err.message}</td></tr>`;
        }
    };

    window.deleteRow = async (id, btn) => {
        if (!confirm('Yakin ingin menghapus data responden ini?')) return;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        btn.disabled = true;
        try {
            const res = await fetch(`/api/results/${id}`, { method: 'DELETE', headers: { 'Authorization': token } });
            const data = await res.json();
            if (data.success) {
                showToast('Data berhasil dihapus.', 'success');
                loadResults(currentPage, currentSearch);
            } else throw new Error(data.message);
        } catch (err) {
            showToast('Gagal menghapus: ' + err.message, 'error');
            btn.innerHTML = '<i class="fa-solid fa-trash"></i>';
            btn.disabled = false;
        }
    };

    const loadStats = async () => {
        try {
            const res = await fetch('/api/stats', { headers: { 'Authorization': token } });
            const data = await res.json();
            if (!data.success) return;

            const { total, today, domisili } = data.stats;
            const topDomisili = domisili && domisili.length ? domisili[0].domisili : '-';

            document.getElementById('stats-row').innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e7f5ff;color:#1971c2"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info"><p>Total Responden</p><h3>${total}</h3></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#ebfbee;color:#2b8a3e"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="stat-info"><p>Domisili Terbanyak</p><h3 style="font-size:.95rem;margin-top:4px">${topDomisili}</h3></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fff3bf;color:#e67700"><i class="fa-solid fa-calendar-day"></i></div>
                    <div class="stat-info"><p>Masuk Hari Ini</p><h3>${today}</h3></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#f3f0ff;color:#7048e8"><i class="fa-solid fa-database"></i></div>
                    <div class="stat-info"><p>Halaman Saat Ini</p><h3 style="font-size:.9rem;margin-top:4px">Hal. ${currentPage} / ${Math.ceil(totalResults/PAGE_LIMIT)||1}</h3></div>
                </div>
            `;
        } catch (e) { /* silent */ }
    };

    const renderPagination = (total, page) => {
        let pg = document.getElementById('pagination-row');
        if (!pg) {
            pg = document.createElement('div');
            pg.id = 'pagination-row';
            pg.style.cssText = 'display:flex;justify-content:center;align-items:center;gap:8px;margin-top:16px;flex-wrap:wrap';
            document.querySelector('#tab-results .card').insertAdjacentElement('afterend', pg);
        }
        const totalPages = Math.ceil(total / PAGE_LIMIT);
        if (totalPages <= 1) { pg.innerHTML = ''; return; }
        let html = '';
        if (page > 1) html += `<button class="btn" onclick="loadResults(${page-1},'${currentSearch}')"><i class="fa-solid fa-chevron-left"></i></button>`;
        for (let p = Math.max(1, page-2); p <= Math.min(totalPages, page+2); p++) {
            html += `<button class="btn ${p===page?'btn-primary':''}" onclick="loadResults(${p},'${currentSearch}')">${p}</button>`;
        }
        if (page < totalPages) html += `<button class="btn" onclick="loadResults(${page+1},'${currentSearch}')"><i class="fa-solid fa-chevron-right"></i></button>`;
        pg.innerHTML = html;
    };

    // Modal Detail — Rendered as Cards, not raw JSON
    window.showDetail = (index) => {
        const result = window._surveyResults[index];
        const resultData = result.raw_data || result;
        const modal = document.getElementById('detail-modal');
        const body = document.getElementById('detail-body');

        const ts = result.submitted_at ? new Date(result.submitted_at).toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'medium' }) : '-';

        let html = `<div class="timestamp-badge"><i class="fa-regular fa-clock"></i> ${ts}</div>`;

        if (typeof surveyConfig !== 'undefined') {
            surveyConfig.forEach(step => {
                html += `<div class="detail-section">
                    <div class="detail-section-title"><i class="fa-solid ${step.icon}"></i> ${step.title}</div>`;

                const walk = (qs) => qs.forEach(q => {
                    if (q.type === 'row') { walk(q.questions); return; }
                    if (!q.name) return;
                    const val = resultData[q.name];
                    if (val === undefined || val === null || val === '') return;

                    let display;
                    if (Array.isArray(val)) {
                        display = `<div class="detail-tags">${val.map(v => `<span class="detail-tag">${v}</span>`).join('')}</div>`;
                    } else {
                        display = `<span class="detail-val">${val}</span>`;
                    }

                    html += `<div class="detail-item">
                        <span class="detail-key">${q.label}</span>
                        ${display}
                    </div>`;
                });
                walk(step.questions);

                html += `</div>`;
            });
        } else {
            // Fallback: render all keys
            Object.entries(result).forEach(([k, v]) => {
                if (k === 'timestamp') return;
                const label = questionLabelMap[k] || k;
                let display = Array.isArray(v)
                    ? `<div class="detail-tags">${v.map(item => `<span class="detail-tag">${item}</span>`).join('')}</div>`
                    : `<span class="detail-val">${v}</span>`;
                html += `<div class="detail-item"><span class="detail-key">${label}</span>${display}</div>`;
            });
        }

        body.innerHTML = html;
        modal.style.display = 'flex';
    };

    document.querySelector('.close-modal').addEventListener('click', () => {
        document.getElementById('detail-modal').style.display = 'none';
    });

    window.addEventListener('click', (e) => {
        if (e.target === document.getElementById('detail-modal')) {
            document.getElementById('detail-modal').style.display = 'none';
        }
    });

    // Search box
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = '🔍 Cari nama, domisili, pekerjaan...';
    searchInput.style.cssText = 'padding:9px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.9rem;width:280px;margin-right:10px';
    document.querySelector('#tab-results .page-header > div:first-child').insertAdjacentElement('afterend', searchInput);
    document.querySelector('#tab-results .btn-outline').style.marginLeft = 'auto';
    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadResults(1, searchInput.value.trim()), 400);
    });

    // Update table header for 8 columns (added Delete)
    document.querySelector('#results-table thead tr').innerHTML = `
        <th>#</th><th>Waktu Pengisian</th><th>Nama</th><th>Usia</th>
        <th>Domisili</th><th>Pekerjaan</th><th>Detail</th><th>Hapus</th>
    `;

    document.getElementById('btn-refresh').addEventListener('click', () => loadResults(currentPage, currentSearch));
    loadResults();

    // ================================================================
    // TAB 2: EDITOR SURVEI (Form-based, like Google Forms)
    // ================================================================
    const stepsContainer = document.getElementById('steps-editor-container');
    const TYPES_WITH_OPTIONS = ['radio', 'checkbox', 'select', 'day-selector'];

    let editorConfig = JSON.parse(JSON.stringify(typeof surveyConfig !== 'undefined' ? surveyConfig : []));

    const renderEditor = () => {
        stepsContainer.innerHTML = '';
        editorConfig.forEach((step, sIdx) => renderStep(step, sIdx));
    };

    const renderStep = (step, sIdx) => {
        const stepDiv = document.createElement('div');
        stepDiv.className = 'step-editor';
        stepDiv.dataset.sIdx = sIdx;

        stepDiv.innerHTML = `
            <div class="step-header">
                <div class="step-num">${sIdx + 1}</div>
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

        // Bind step title/desc changes
        stepDiv.querySelector('.step-title-input').addEventListener('input', (e) => {
            editorConfig[sIdx].title = e.target.value;
        });
        stepDiv.querySelector('.step-desc-input').addEventListener('input', (e) => {
            editorConfig[sIdx].description = e.target.value;
        });

        // Delete step
        stepDiv.querySelector('.btn-del-step').addEventListener('click', () => {
            if (editorConfig.length <= 1) { alert('Minimal harus ada 1 bagian.'); return; }
            if (confirm(`Hapus bagian "${step.title}"? Semua pertanyaan di dalamnya akan ikut terhapus.`)) {
                editorConfig.splice(sIdx, 1);
                renderEditor();
            }
        });

        // Render existing questions (flatten row types)
        const flattenedQuestions = [];
        (step.questions || []).forEach(q => {
            if (q.type === 'row') {
                q.questions.forEach(subQ => flattenedQuestions.push(subQ));
            } else {
                flattenedQuestions.push(q);
            }
        });

        // Store flat questions back so editor state is clean
        editorConfig[sIdx]._flatQ = flattenedQuestions;

        const ql = stepDiv.querySelector('.questions-list');
        flattenedQuestions.forEach((q, qIdx) => renderQuestion(q, qIdx, sIdx, ql));

        // Add question button
        stepDiv.querySelector('.btn-add-question').addEventListener('click', () => {
            const newQ = { type: 'text', name: '', label: '', required: false };
            editorConfig[sIdx]._flatQ.push(newQ);
            renderQuestion(newQ, editorConfig[sIdx]._flatQ.length - 1, sIdx, ql);
        });

        stepsContainer.appendChild(stepDiv);
    };

    const renderQuestion = (q, qIdx, sIdx, container) => {
        const tpl = document.getElementById('question-tpl');
        const clone = tpl.content.cloneNode(true);
        const card = clone.querySelector('.question-card');
        card.dataset.qIdx = qIdx;

        const typeSelect = card.querySelector('.q-type-select');
        typeSelect.value = q.type || 'text';

        const labelInput = card.querySelector('.q-label');
        labelInput.value = q.label || '';

        const nameInput = card.querySelector('.q-name');
        nameInput.value = q.name || '';

        const reqToggle = card.querySelector('.q-required');
        reqToggle.checked = !!q.required;

        const optionsSection = card.querySelector('.q-options-section');
        const optionsList = card.querySelector('.q-options-list');
        const hasOtherToggle = card.querySelector('.q-has-other');
        hasOtherToggle.checked = !!q.hasOther;

        const showHideOptions = (type) => {
            optionsSection.style.display = TYPES_WITH_OPTIONS.includes(type) ? 'block' : 'none';
        };

        showHideOptions(q.type);

        // Render existing options
        if (q.options && q.options.length) {
            q.options.forEach(opt => addOptionRow(optionsList, opt.label || opt.value || ''));
        } else if (TYPES_WITH_OPTIONS.includes(q.type) && (!q.options || !q.options.length)) {
            addOptionRow(optionsList, '');
        }

        // Type change
        typeSelect.addEventListener('change', (e) => {
            const newType = e.target.value;
            editorConfig[sIdx]._flatQ[qIdx].type = newType;
            showHideOptions(newType);
            if (TYPES_WITH_OPTIONS.includes(newType) && optionsList.children.length === 0) {
                addOptionRow(optionsList, '');
            }
        });

        // Add option button
        card.querySelector('.btn-add-option').addEventListener('click', () => addOptionRow(optionsList, ''));

        // Label / name / required binding
        labelInput.addEventListener('input', e => { editorConfig[sIdx]._flatQ[qIdx].label = e.target.value; });
        nameInput.addEventListener('input', e => { editorConfig[sIdx]._flatQ[qIdx].name = e.target.value; });
        reqToggle.addEventListener('change', e => { editorConfig[sIdx]._flatQ[qIdx].required = e.target.checked; });
        hasOtherToggle.addEventListener('change', e => { editorConfig[sIdx]._flatQ[qIdx].hasOther = e.target.checked; });

        // Delete question
        card.querySelector('.btn-delete-question').addEventListener('click', () => {
            if (confirm('Hapus pertanyaan ini?')) {
                editorConfig[sIdx]._flatQ.splice(qIdx, 1);
                // Re-render step
                const stepDiv = stepsContainer.querySelector(`[data-s-idx="${sIdx}"]`) ||
                                stepsContainer.querySelectorAll('.step-editor')[sIdx];
                const ql = stepDiv.querySelector('.questions-list');
                ql.innerHTML = '';
                editorConfig[sIdx]._flatQ.forEach((q2, i) => renderQuestion(q2, i, sIdx, ql));
            }
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
        row.querySelector('.btn-del-option').addEventListener('click', () => {
            row.remove();
        });
        list.appendChild(row);
    };

    // Add new Step
    document.getElementById('btn-add-step').addEventListener('click', () => {
        const newStep = {
            id: `step-${editorConfig.length + 1}`,
            title: `Bagian ${editorConfig.length + 1}`,
            icon: 'fa-list',
            description: '',
            questions: [],
            _flatQ: []
        };
        editorConfig.push(newStep);
        renderEditor();
    });

    // Save Config
    document.getElementById('btn-save-config').addEventListener('click', async () => {
        // Read current DOM state and build config
        const finalConfig = [];

        stepsContainer.querySelectorAll('.step-editor').forEach((stepDiv, sIdx) => {
            const stepData = editorConfig[sIdx];
            const questions = [];

            stepDiv.querySelectorAll('.question-card').forEach((card) => {
                const qIdx = parseInt(card.dataset.qIdx);
                const type = card.querySelector('.q-type-select').value;
                const label = card.querySelector('.q-label').value.trim();
                const name = card.querySelector('.q-name').value.trim();
                const required = card.querySelector('.q-required').checked;
                const hasOther = card.querySelector('.q-has-other').checked;

                if (!name || !label) return; // Skip empty questions

                const q = { type, name, label, required };

                if (TYPES_WITH_OPTIONS.includes(type)) {
                    const options = [];
                    card.querySelectorAll('.option-row input[type="text"]').forEach(inp => {
                        const txt = inp.value.trim();
                        if (txt) options.push({ value: txt, label: txt });
                    });
                    q.options = options;
                    if (hasOther) q.hasOther = true;
                    if (q.otherPlaceholder) q.otherPlaceholder = q.otherPlaceholder;
                }

                questions.push(q);
            });

            finalConfig.push({
                id: stepData.id || `step-${sIdx + 1}`,
                title: stepData.title || stepDiv.querySelector('.step-title-input').value,
                icon: stepData.icon || 'fa-list',
                description: stepDiv.querySelector('.step-desc-input').value.trim(),
                questions
            });
        });

        const btn = document.getElementById('btn-save-config');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
        btn.disabled = true;

        try {
            const res = await fetch('/api/questions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': token },
                body: JSON.stringify(finalConfig)
            });
            const data = await res.json();

            if (data.success) {
                // Show success toast
                showToast('✅ Berhasil disimpan! Refresh halaman survei untuk melihat perubahan.', 'success');
                editorConfig = finalConfig;
            } else {
                throw new Error(data.message);
            }
        } catch (err) {
            showToast(`❌ Gagal menyimpan: ${err.message}`, 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan';
            btn.disabled = false;
        }
    });

    // Toast notification
    const showToast = (msg, type = 'success') => {
        let toast = document.getElementById('admin-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'admin-toast';
            toast.style.cssText = `
                position:fixed; bottom:28px; right:28px; padding:14px 20px;
                border-radius:12px; font-weight:600; font-size:0.9rem;
                box-shadow:0 8px 24px rgba(0,0,0,0.15); z-index:999;
                transition:all 0.4s; opacity:0; transform:translateY(10px);
            `;
            document.body.appendChild(toast);
        }
        toast.innerText = msg;
        toast.style.background = type === 'success' ? '#ebfbee' : '#fff5f5';
        toast.style.color = type === 'success' ? '#2b8a3e' : '#e03131';
        toast.style.border = `1.5px solid ${type === 'success' ? '#b2f2bb' : '#ffc9c9'}`;
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
        }, 3500);
    };

    // Initialize editor
    renderEditor();
});
