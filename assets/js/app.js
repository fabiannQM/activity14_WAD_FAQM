const { createApp } = Vue;

const apiBaseUrl = window.location.port === '5500'
    ? 'http://localhost/Application-Web-Design/activity14/api.php'
    : 'api.php';

createApp({
    data() {
        return {
            apiBase: apiBaseUrl,
            notes: [],
            categories: [],
            classificationFilter: '',
            editingId: null,
            loading: false,
            status: '',
            isError: false,
            authMode: 'signup',
            token: localStorage.getItem('notes_api_token') || '',
            currentUser: null,
            authStatus: '',
            authIsError: false,
            authForm: {
                name: '',
                email: '',
                password: '',
                remember_me: false
            },
            form: {
                title: '',
                author: '',
                created_at: '',
                body: '',
                classification: 'personal'
            }
        };
    },
    mounted() {
        this.form.created_at = this.toDateTimeLocal(new Date());
        this.loadCategories();
        this.loadNotes();
    },
    methods: {
        async request(path, options = {}) {
            const headers = {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {})
            };

            if (this.token) {
                headers.Authorization = `Bearer ${this.token}`;
            }

            const response = await fetch(`${this.apiBase}${path}`, {
                headers,
                ...options
            });
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('The API returned HTML instead of JSON. Open the app through XAMPP or make sure Apache is running.');
            }
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || data.message || 'API request failed');
            }
            return data;
        },
        async signUp() {
            try {
                const data = await this.request('/auth/signup', {
                    method: 'POST',
                    body: JSON.stringify({
                        name: this.authForm.name,
                        email: this.authForm.email,
                        password: this.authForm.password
                    })
                });
                this.showAuthStatus(data.message);
                this.authMode = 'login';
            } catch (error) {
                this.showAuthStatus(error.message, true);
            }
        },
        async login() {
            try {
                const data = await this.request('/auth/login', {
                    method: 'POST',
                    body: JSON.stringify({
                        email: this.authForm.email,
                        password: this.authForm.password,
                        remember_me: this.authForm.remember_me
                    })
                });
                this.token = data.access_token;
                this.currentUser = data.user;
                localStorage.setItem('notes_api_token', this.token);
                this.showAuthStatus(`Token created. Expires at ${data.expires_at}.`);
            } catch (error) {
                this.showAuthStatus(error.message, true);
            }
        },
        async loadUser() {
            try {
                const data = await this.request('/auth/user');
                this.currentUser = data;
                this.showAuthStatus('Authenticated user loaded.');
            } catch (error) {
                this.showAuthStatus(error.message, true);
            }
        },
        async logout() {
            try {
                const data = await this.request('/auth/logout');
                this.token = '';
                this.currentUser = null;
                localStorage.removeItem('notes_api_token');
                this.showAuthStatus(data.message);
            } catch (error) {
                this.showAuthStatus(error.message, true);
            }
        },
        async loadCategories() {
            try {
                const data = await this.request('/categories');
                this.categories = data.categories;
            } catch (error) {
                this.showStatus(error.message, true);
            }
        },
        async loadNotes() {
            try {
                const query = this.classificationFilter
                    ? `?classification=${encodeURIComponent(this.classificationFilter)}`
                    : '';
                const data = await this.request(`/notes${query}`);
                this.notes = data.notes;
            } catch (error) {
                this.showStatus(error.message, true);
            }
        },
        async saveNote() {
            this.loading = true;
            this.showStatus('');
            try {
                const payload = {
                    ...this.form,
                    created_at: this.fromDateTimeLocal(this.form.created_at)
                };
                const path = this.editingId ? `/notes/${this.editingId}` : '/notes';
                const method = this.editingId ? 'PUT' : 'POST';
                await this.request(path, {
                    method,
                    body: JSON.stringify(payload)
                });
                this.showStatus(this.editingId ? 'Note updated.' : 'Note created.');
                this.resetForm();
                await this.loadCategories();
                await this.loadNotes();
            } catch (error) {
                this.showStatus(error.message, true);
            } finally {
                this.loading = false;
            }
        },
        editNote(note) {
            this.editingId = note.id;
            this.form = {
                title: note.title,
                author: note.author,
                created_at: this.toDateTimeLocal(note.created_at),
                body: note.body,
                classification: note.classification
            };
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        async deleteNote(id) {
            if (!confirm('Delete this note?')) {
                return;
            }
            try {
                await this.request(`/notes/${id}`, { method: 'DELETE' });
                this.showStatus('Note deleted.');
                await this.loadNotes();
            } catch (error) {
                this.showStatus(error.message, true);
            }
        },
        resetForm() {
            this.editingId = null;
            this.form = {
                title: '',
                author: '',
                created_at: this.toDateTimeLocal(new Date()),
                body: '',
                classification: 'personal'
            };
        },
        showStatus(message, isError = false) {
            this.status = message;
            this.isError = isError;
        },
        showAuthStatus(message, isError = false) {
            this.authStatus = message;
            this.authIsError = isError;
        },
        toDateTimeLocal(value) {
            const date = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return '';
            }
            const pad = (number) => String(number).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
        },
        fromDateTimeLocal(value) {
            return value ? value.replace('T', ' ') + ':00' : '';
        }
    }
}).mount('#app');
