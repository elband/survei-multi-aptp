document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('dynamic-form-container');
    const stepsWrapper = document.querySelector('.steps-wrapper');
    const progressBar = document.getElementById('progress-bar');
    const btnNext = document.getElementById('btn-next');
    const btnPrev = document.getElementById('btn-prev');
    const btnSubmit = document.getElementById('btn-submit');
    const form = document.getElementById('surveyForm');
    const successMessage = document.getElementById('success-message');

    let currentStep = 0;
    const totalSteps = surveyConfig.length;

    // Jumlah upload yang sedang berjalan. Selama > 0, tombol navigasi &
    // kirim dinonaktifkan agar responden tidak mengirim form dengan
    // nilai path yang belum terisi.
    let pendingUploads = 0;
    const setNavBusy = () => {
        const busy = pendingUploads > 0;
        btnNext.disabled = busy;
        btnSubmit.disabled = busy;
    };

    // Helper to generate unique IDs
    let idCounter = 0;
    const generateId = (prefix) => `${prefix}-${idCounter++}`;

    // Render Steps Indicators
    const renderIndicators = () => {
        stepsWrapper.innerHTML = '';
        surveyConfig.forEach((_, index) => {
            const indicator = document.createElement('div');
            indicator.className = `step-indicator ${index === 0 ? 'active' : ''}`;
            indicator.setAttribute('data-step', index + 1);
            indicator.innerText = index + 1;
            stepsWrapper.appendChild(indicator);
        });
    };

    // Render Form Questions
    const renderForm = () => {
        container.innerHTML = '';

        if (!surveyConfig || surveyConfig.length === 0) {
            container.innerHTML = '<div class="empty-survey"><i class="fa-solid fa-circle-exclamation"></i><p>Belum ada pertanyaan untuk survei ini.</p></div>';
            return;
        }

        surveyConfig.forEach((step, index) => {
            const stepDiv = document.createElement('div');
            stepDiv.className = `form-step ${index === 0 ? 'active' : ''}`;
            stepDiv.id = step.id;

            // Header
            const h2 = document.createElement('h2');
            h2.innerHTML = `<i class="fa-solid ${step.icon}"></i> ${step.title}`;
            stepDiv.appendChild(h2);

            if (step.description) {
                const p = document.createElement('p');
                p.className = 'section-desc';
                p.innerText = step.description;
                stepDiv.appendChild(p);
            }

            // Render Questions
            step.questions.forEach(q => renderQuestion(q, stepDiv));
            container.appendChild(stepDiv);
        });
    };

    const renderQuestion = (q, parentDiv) => {
        if (q.type === 'row') {
            const rowDiv = document.createElement('div');
            rowDiv.className = 'row-group';
            q.questions.forEach(subQ => {
                const halfDiv = document.createElement('div');
                halfDiv.className = 'input-group half';
                renderSingleInput(subQ, halfDiv);
                rowDiv.appendChild(halfDiv);
            });
            parentDiv.appendChild(rowDiv);
        } else {
            const groupDiv = document.createElement('div');
            groupDiv.className = 'input-group';
            renderSingleInput(q, groupDiv);
            parentDiv.appendChild(groupDiv);
        }
    };

    const renderSingleInput = (q, wrapper) => {
        const label = document.createElement('label');
        label.innerHTML = `${q.label} ${q.required ? '<span class="required">*</span>' : ''}`;
        if (q.type === 'text') label.setAttribute('for', q.name);
        wrapper.appendChild(label);

        // Gambar ilustrasi — berlaku untuk semua tipe pertanyaan
        if (q.imageUrl) {
            const illus = document.createElement('img');
            illus.className = 'q-illustration';
            illus.src = q.imageUrl;
            illus.alt = q.label || 'Ilustrasi pertanyaan';
            illus.loading = 'lazy';
            wrapper.appendChild(illus);
        }

        if (q.type === 'text' || q.type === 'textarea') {
            const input = document.createElement(q.type === 'text' ? 'input' : 'textarea');
            if (q.type === 'text') input.type = 'text';
            else input.rows = 3;
            input.id = q.name;
            input.name = q.name;
            input.placeholder = q.placeholder || '';
            if (q.required) input.required = true;
            wrapper.appendChild(input);
        } else if (q.type === 'select') {
            const select = document.createElement('select');
            select.name = q.name;
            if (q.required) select.required = true;

            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.disabled = true;
            defOpt.selected = true;
            defOpt.innerText = 'Pilih...';
            select.appendChild(defOpt);

            if (q.options && Array.isArray(q.options)) {
                q.options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.innerText = opt.label;
                    select.appendChild(option);
                });
            }
            wrapper.appendChild(select);
        } else if (q.type === 'radio' || q.type === 'checkbox') {
            const group = document.createElement('div');
            group.className = q.type === 'radio' ? 'radio-group' : 'checkbox-group';

            if (q.options && Array.isArray(q.options)) {
                q.options.forEach(opt => {
                    const labelCard = document.createElement('label');
                    labelCard.className = q.type === 'radio' ? 'radio-card' : 'checkbox-card';

                    const input = document.createElement('input');
                    input.type = q.type;
                    input.name = q.name;
                    input.value = opt.value;
                    if (q.required && q.type === 'radio') input.required = true;

                    const span = document.createElement('span');
                    span.className = q.type === 'radio' ? 'checkmark' : 'check-box';

                    labelCard.appendChild(input);
                    labelCard.appendChild(span);
                    labelCard.appendChild(document.createTextNode(' ' + opt.label));
                    group.appendChild(labelCard);
                });
            }

            if (q.hasOther) {
                const otherId = generateId(`other-${q.name}`);
                const textId = `${otherId}-text`;

                const labelCard = document.createElement('label');
                labelCard.className = `${q.type === 'radio' ? 'radio-card' : 'checkbox-card'} custom-input-wrapper`;

                const input = document.createElement('input');
                input.type = q.type;
                input.name = q.name;
                input.value = 'lainnya';
                input.id = otherId;

                const span = document.createElement('span');
                span.className = q.type === 'radio' ? 'checkmark' : 'check-box';

                const textInput = document.createElement('input');
                textInput.type = 'text';
                textInput.id = textId;
                textInput.className = 'inline-text';
                textInput.placeholder = q.otherPlaceholder || 'Sebutkan...';
                textInput.disabled = true;

                labelCard.appendChild(input);
                labelCard.appendChild(span);
                labelCard.appendChild(document.createTextNode(' Lainnya: '));
                labelCard.appendChild(textInput);
                group.appendChild(labelCard);

                // Setup Other toggle
                group.addEventListener('change', (e) => {
                    if (e.target.type === q.type && e.target.name === q.name) {
                        if (q.type === 'radio') {
                            if (e.target.id === otherId) {
                                textInput.disabled = false;
                                textInput.required = true;
                                textInput.focus();
                            } else {
                                textInput.disabled = true;
                                textInput.required = false;
                                textInput.value = '';
                            }
                        } else if (q.type === 'checkbox') {
                            if (e.target.id === otherId) {
                                textInput.disabled = !e.target.checked;
                                textInput.required = e.target.checked;
                                if (e.target.checked) textInput.focus();
                                else textInput.value = '';
                            }
                        }
                    }
                });
            }

            wrapper.appendChild(group);
        } else if (q.type === 'day-selector') {
            const group = document.createElement('div');
            group.className = 'day-selector';
            if (q.options && Array.isArray(q.options)) {
                q.options.forEach(opt => {
                    const labelCard = document.createElement('label');
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.name = q.name;
                    input.value = opt.value;
                    const span = document.createElement('span');
                    span.innerText = opt.label;
                    labelCard.appendChild(input);
                    labelCard.appendChild(span);
                    group.appendChild(labelCard);
                });
            }
            wrapper.appendChild(group);
        } else if (q.type === 'image-upload') {
            renderImageUpload(q, wrapper);
        }
    };

    // ---- Widget Unggah Gambar (jawaban responden) ----
    const MAX_DIMENSION = 1600;
    const JPEG_QUALITY = 0.85;

    /**
     * Perkecil gambar via canvas sebelum dikirim. Foto HP 6 MB menjadi ~300 KB,
     * sehingga terhindar dari batas upload_max_filesize hosting yang ketat.
     * Jika browser tidak mendukung, file asli dipakai apa adanya.
     */
    const compressImage = (file) => new Promise((resolve) => {
        if (!window.HTMLCanvasElement || !file.type.startsWith('image/')) return resolve(file);

        const img = new Image();
        const objUrl = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(objUrl);
            try {
                const scale = Math.min(1, MAX_DIMENSION / Math.max(img.width, img.height));
                // Sudah cukup kecil dan bukan format yang perlu dikonversi
                if (scale === 1 && file.size <= 1024 * 1024) return resolve(file);

                const canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

                if (!canvas.toBlob) return resolve(file);
                canvas.toBlob((blob) => {
                    if (!blob || blob.size >= file.size) return resolve(file);
                    resolve(new File([blob], 'foto.jpg', { type: 'image/jpeg' }));
                }, 'image/jpeg', JPEG_QUALITY);
            } catch (e) {
                resolve(file);
            }
        };
        img.onerror = () => { URL.revokeObjectURL(objUrl); resolve(file); };
        img.src = objUrl;
    });

    const renderImageUpload = (q, wrapper) => {
        const box = document.createElement('div');
        box.className = 'image-upload-box';

        // Pembawa nilai. HARUS type="hidden": validateCurrentStep menyeleksi
        // input[type="text"] dan akan salah memvalidasinya jika bukan.
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = q.name;
        hidden.value = '';

        // TANPA atribut name — FormData akan memungutnya sebagai objek File
        // dan JSON.stringify mengubahnya menjadi {}.
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/jpeg,image/png,image/webp';
        fileInput.className = 'image-upload-input';

        const btnPick = document.createElement('button');
        btnPick.type = 'button';
        btnPick.className = 'btn-upload';
        btnPick.innerHTML = '<i class="fa-solid fa-camera"></i> Pilih Gambar';

        const preview = document.createElement('div');
        preview.className = 'image-upload-preview';
        preview.style.display = 'none';

        const previewImg = document.createElement('img');
        const btnRemove = document.createElement('button');
        btnRemove.type = 'button';
        btnRemove.className = 'btn-upload-remove';
        btnRemove.innerHTML = '<i class="fa-solid fa-trash"></i> Hapus';
        preview.appendChild(previewImg);
        preview.appendChild(btnRemove);

        const bar = document.createElement('div');
        bar.className = 'upload-progress';
        bar.style.display = 'none';
        const barFill = document.createElement('div');
        barFill.className = 'upload-progress-fill';
        bar.appendChild(barFill);

        const status = document.createElement('div');
        status.className = 'image-upload-status';

        box.appendChild(hidden);
        box.appendChild(fileInput);
        box.appendChild(btnPick);
        box.appendChild(preview);
        box.appendChild(bar);
        box.appendChild(status);
        wrapper.appendChild(box);

        const reset = () => {
            hidden.value = '';
            previewImg.removeAttribute('src');
            preview.style.display = 'none';
            btnPick.innerHTML = '<i class="fa-solid fa-camera"></i> Pilih Gambar';
            fileInput.value = '';
        };

        btnPick.addEventListener('click', () => fileInput.click());
        btnRemove.addEventListener('click', () => { reset(); status.textContent = ''; });

        fileInput.addEventListener('change', async () => {
            const raw = fileInput.files && fileInput.files[0];
            if (!raw) return;

            // Preview instan sebelum upload selesai
            const localUrl = URL.createObjectURL(raw);
            previewImg.src = localUrl;
            preview.style.display = 'flex';
            status.textContent = 'Menyiapkan gambar...';
            status.className = 'image-upload-status';

            const file = await compressImage(raw);

            const fd = new FormData();
            fd.append('mode', 'answer');
            fd.append('survey_id', document.querySelector('input[name="survey_id"]').value);
            fd.append('field', q.name);
            fd.append('file', file);

            pendingUploads++;
            setNavBusy();
            btnPick.disabled = true;
            bar.style.display = 'block';
            barFill.style.width = '0%';
            status.textContent = 'Mengunggah...';

            // XHR, bukan fetch — hanya XHR yang punya upload.onprogress
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload_image.php');

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    barFill.style.width = Math.round((e.loaded / e.total) * 100) + '%';
                }
            };

            xhr.onload = () => {
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* ditangani di bawah */ }

                if (data && data.success) {
                    hidden.value = data.path;
                    previewImg.src = data.path;
                    btnPick.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Ganti Gambar';
                    status.textContent = 'Gambar berhasil diunggah.';
                    status.className = 'image-upload-status success';
                } else {
                    reset();
                    status.textContent = (data && data.message) ? data.message : 'Gagal mengunggah gambar. Silakan coba lagi.';
                    status.className = 'image-upload-status error';
                }
            };

            xhr.onerror = () => {
                reset();
                status.textContent = 'Koneksi terputus saat mengunggah. Silakan coba lagi.';
                status.className = 'image-upload-status error';
            };

            xhr.onloadend = () => {
                URL.revokeObjectURL(localUrl);
                pendingUploads--;
                setNavBusy();
                btnPick.disabled = false;
                bar.style.display = 'none';
            };

            xhr.send(fd);
        });
    };

    // Toggle Styles for Custom Radios/Checkboxes Focus/Selected
    const setupStyleCards = () => {
        container.addEventListener('change', (e) => {
            const target = e.target;
            if (target.type === 'radio' || target.type === 'checkbox') {
                // If it's a radio inside a card, unselect others
                if (target.type === 'radio') {
                    const groupName = target.name;
                    document.querySelectorAll(`input[name="${groupName}"]`).forEach(radio => {
                        const card = radio.closest('.radio-card');
                        if (card) card.classList.remove('selected');
                    });
                }

                const card = target.closest('.radio-card, .checkbox-card, .day-selector label');
                if (card) {
                    if (target.checked) {
                        card.classList.add('selected'); // for radio/checkbox cards
                    } else {
                        card.classList.remove('selected');
                    }
                }
            }
        });
    };

    // Initialization
    renderIndicators();
    renderForm();
    setupRegionSelectors(container);
    setupStyleCards();

    // DOM References after render
    const steps = document.querySelectorAll('.form-step');
    const stepIndicators = document.querySelectorAll('.step-indicator');

    // Update UI based on Current Step
    const updateUI = () => {
        // Steps Visibility
        steps.forEach((step, index) => {
            if (index === currentStep) {
                step.classList.add('active');
            } else {
                step.classList.remove('active');
            }
        });

        // Indicators
        stepIndicators.forEach((indicator, index) => {
            if (index < currentStep) {
                indicator.classList.add('completed');
                indicator.classList.remove('active');
            } else if (index === currentStep) {
                indicator.classList.add('active');
                indicator.classList.remove('completed');
            } else {
                indicator.classList.remove('completed');
                indicator.classList.remove('active');
            }
        });

        // Progress Bar
        const progressPercentage = (currentStep / (totalSteps - 1)) * 100;
        progressBar.style.width = `${progressPercentage}%`;

        // Buttons
        if (currentStep === 0) {
            btnPrev.classList.add('hidden');
        } else {
            btnPrev.classList.remove('hidden');
        }

        if (currentStep === totalSteps - 1) {
            btnNext.classList.add('hidden');
            btnSubmit.classList.remove('hidden');
        } else {
            btnNext.classList.remove('hidden');
            btnSubmit.classList.add('hidden');
        }

        // Scroll to top of app-container
        document.querySelector('.app-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // Validation
    const validateCurrentStep = () => {
        const currentStepEl = steps[currentStep];
        const stepConfig = surveyConfig[currentStep];

        let isValid = true;
        let firstInvalid = null;

        // Collect all required names from config for this step
        const requiredNames = [];
        const processQuestions = (qs) => {
            qs.forEach(q => {
                if (q.type === 'row') {
                    processQuestions(q.questions);
                } else if (q.required) {
                    requiredNames.push({ name: q.name, type: q.type });
                }
            });
        };
        processQuestions(stepConfig.questions);

        // Validate text, textarea, select
        const inputs = currentStepEl.querySelectorAll('input[type="text"], textarea, select');
        inputs.forEach(input => {
            if (input.required && !input.value.trim() && !input.disabled) {
                isValid = false;
                firstInvalid = firstInvalid || input;
                input.style.borderColor = "var(--error)";
            } else {
                input.style.borderColor = "var(--border)";
            }
        });

        // Validate grouped required fields (checkboxes, radios, day-selector)
        requiredNames.forEach(req => {
            if (req.type === 'image-upload') {
                const h = currentStepEl.querySelector(`input[type="hidden"][name="${req.name}"]`);
                if (!h) return;
                const containerElement = h.closest('.input-group');
                if (!h.value) {
                    isValid = false;
                    if (containerElement) {
                        containerElement.style.borderLeft = "4px solid var(--error)";
                        containerElement.style.paddingLeft = "10px";
                    }
                    // Hidden input tidak bisa difokus — arahkan ke tombol pilih
                    firstInvalid = firstInvalid || h.parentElement.querySelector('.btn-upload');
                } else if (containerElement) {
                    containerElement.style.borderLeft = "none";
                    containerElement.style.paddingLeft = "0";
                }
            } else if (req.type === 'radio' || req.type === 'checkbox' || req.type === 'day-selector') {
                const groupInputs = currentStepEl.querySelectorAll(`input[name="${req.name}"]`);
                if (groupInputs.length > 0) {
                    const isChecked = Array.from(groupInputs).some(cb => cb.checked);
                    const containerElement = groupInputs[0].closest('.input-group') || groupInputs[0].closest('.day-selector').parentElement;

                    if (!isChecked) {
                        isValid = false;
                        if (containerElement) {
                            containerElement.style.borderLeft = "4px solid var(--error)";
                            containerElement.style.paddingLeft = "10px";
                        }
                        firstInvalid = firstInvalid || groupInputs[0];
                    } else {
                        if (containerElement) {
                            containerElement.style.borderLeft = "none";
                            containerElement.style.paddingLeft = "0";
                        }
                    }
                }
            }
        });

        if (!isValid && firstInvalid) {
            firstInvalid.focus();
        }

        return isValid;
    };

    // Navigation Events
    btnNext.addEventListener('click', () => {
        if (validateCurrentStep()) {
            if (currentStep < totalSteps - 1) {
                currentStep++;
                updateUI();
            }
        }
    });

    btnPrev.addEventListener('click', () => {
        if (currentStep > 0) {
            currentStep--;
            updateUI();
        }
    });

    // Handle Form Submission
    form.addEventListener('submit', (e) => {
        e.preventDefault();

        if (pendingUploads > 0) {
            alert('Mohon tunggu, gambar sedang diunggah.');
            return;
        }

        if (validateCurrentStep()) {
            const formData = new FormData(form);
            const dataObj = {};

            for (let [key, value] of formData.entries()) {
                if (dataObj[key]) {
                    if (!Array.isArray(dataObj[key])) {
                        dataObj[key] = [dataObj[key]];
                    }
                    dataObj[key].push(value);
                } else {
                    dataObj[key] = value;
                }
            }

            // Replace "lainnya" with actual text value
            surveyConfig.forEach(step => {
                const processQs = (qs) => {
                    qs.forEach(q => {
                        if (q.type === 'row') processQs(q.questions);
                        else if (q.hasOther) {
                            if (dataObj[q.name]) {
                                const textInput = document.querySelector(`input[name="${q.name}"][value="lainnya"]`).parentElement.querySelector('.inline-text');
                                if (textInput && textInput.value) {
                                    if (Array.isArray(dataObj[q.name])) {
                                        dataObj[q.name] = dataObj[q.name].map(item => item === 'lainnya' ? textInput.value : item);
                                    } else if (dataObj[q.name] === 'lainnya') {
                                        dataObj[q.name] = textInput.value;
                                    }
                                }
                            }
                        }
                    });
                };
                processQs(step.questions);
            });

            console.log('--- SURVEI SUBMITTED (DYNAMIC) ---');
            console.log('Payload:', JSON.stringify(dataObj, null, 2));

            const originalBtnText = btnSubmit.innerHTML;
            btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengirim...';
            btnSubmit.disabled = true;

            const API_URL = 'proses_survei.php';

            fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(dataObj)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        form.classList.add('hidden');
                        successMessage.classList.remove('hidden');
                        document.querySelector('.progress-container').classList.add('hidden');
                    } else {
                        throw new Error('Gagal menyimpan');
                    }
                })
                .catch(error => {
                    console.error('Error!', error.message);
                    alert('Terjadi kesalahan saat mengirim data. Silakan coba lagi.');
                })
                .finally(() => {
                    btnSubmit.innerHTML = originalBtnText;
                    btnSubmit.disabled = false;
                });
        }
    });

    // Initializer
    updateUI();
});
