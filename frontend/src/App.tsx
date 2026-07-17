import { useState, useEffect, type FormEvent } from 'react';
import api from './services/api';

interface Note {
  id: number;
  title: string;
  content: string;
  summary?: string;
  category: string;
  vector_id?: string;
  created_at: string;
  updated_at: string;
}

interface User {
  id: number;
  name: string;
  email: string;
}

interface Toast {
  id: number;
  message: string;
  type: 'success' | 'danger' | 'warning';
}

function App() {
  // Authentication & Navigation
  const [token, setToken] = useState<string | null>(() => localStorage.getItem('token'));
  const [user, setUser] = useState<User | null>(() => {
    const saved = localStorage.getItem('user');
    return saved ? JSON.parse(saved) : null;
  });
  const [currentView, setCurrentView] = useState<'login' | 'register' | 'dashboard'>(() => 
    localStorage.getItem('token') ? 'dashboard' : 'login'
  );

  // Auth Form State
  const [authName, setAuthName] = useState('');
  const [authEmail, setAuthEmail] = useState('');
  const [authPassword, setAuthPassword] = useState('');
  const [authConfirmPassword, setAuthConfirmPassword] = useState('');
  const [authError, setAuthError] = useState('');

  // Notes Dashboard State
  const [notes, setNotes] = useState<Note[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [isSemantic, setIsSemantic] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [totalNotes, setTotalNotes] = useState(0);
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  
  // Note Modal Editor State
  const [isEditorOpen, setIsEditorOpen] = useState(false);
  const [editingNote, setEditingNote] = useState<Note | null>(null);
  const [noteTitle, setNoteTitle] = useState('');
  const [noteContent, setNoteContent] = useState('');
  const [noteCategory, setNoteCategory] = useState<string>('work');
  
  // App Action Loaders
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isSummarizing, setIsSummarizing] = useState(false);
  
  // Alerts and Theme
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    const saved = localStorage.getItem('theme');
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  });

  // Apply visual theme
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
  }, [theme]);

  // Intercept Sanctum token expiration/401 errors
  useEffect(() => {
    const handleAuthFailure = () => {
      setToken(null);
      setUser(null);
      setCurrentView('login');
      showToast('Session expired. Please log in again.', 'warning');
    };
    window.addEventListener('auth-failure', handleAuthFailure);
    return () => window.removeEventListener('auth-failure', handleAuthFailure);
  }, []);

  // Fetch Notes on dashboard load/page changes
  useEffect(() => {
    if (token && currentView === 'dashboard' && !isSemantic) {
      fetchNotes(currentPage, searchQuery);
    }
  }, [currentPage, isSemantic, token, currentView, selectedCategory]);

  const toggleTheme = () => {
    setTheme(prev => prev === 'light' ? 'dark' : 'light');
  };

  const showToast = (message: string, type: 'success' | 'danger' | 'warning' = 'success') => {
    const id = Date.now();
    setToasts(prev => [...prev, { id, message, type }]);
    setTimeout(() => {
      setToasts(prev => prev.filter(t => t.id !== id));
    }, 4000);
  };

  // ==========================================
  // AUTHENTICATION LOGIC
  // ==========================================

  const handleRegister = async (e: FormEvent) => {
    e.preventDefault();
    setAuthError('');
    if (authPassword !== authConfirmPassword) {
      setAuthError('Passwords do not match');
      return;
    }
    setIsSaving(true);
    try {
      const res = await api.post('/register', {
        name: authName,
        email: authEmail,
        password: authPassword,
        password_confirmation: authConfirmPassword
      });
      
      const { token, user } = res.data;
      localStorage.setItem('token', token);
      localStorage.setItem('user', JSON.stringify(user));
      
      setToken(token);
      setUser(user);
      setCurrentView('dashboard');
      showToast('Registration successful! Welcome.', 'success');
      
      // Reset forms
      setAuthName('');
      setAuthEmail('');
      setAuthPassword('');
      setAuthConfirmPassword('');
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.errors?.email?.[0] || 'Registration failed';
      setAuthError(msg);
      showToast(msg, 'danger');
    } finally {
      setIsSaving(false);
    }
  };

  const handleLogin = async (e: FormEvent) => {
    e.preventDefault();
    setAuthError('');
    setIsSaving(true);
    try {
      const res = await api.post('/login', {
        email: authEmail,
        password: authPassword
      });
      
      const { token, user } = res.data;
      localStorage.setItem('token', token);
      localStorage.setItem('user', JSON.stringify(user));
      
      setToken(token);
      setUser(user);
      setCurrentView('dashboard');
      showToast('Logged in successfully', 'success');
      
      // Reset forms
      setAuthEmail('');
      setAuthPassword('');
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Invalid credentials';
      setAuthError(msg);
      showToast(msg, 'danger');
    } finally {
      setIsSaving(false);
    }
  };

  const handleLogout = async () => {
    try {
      await api.post('/logout');
    } catch (err) {
      // Continue client cleanup even if api fails
    }
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setToken(null);
    setUser(null);
    setCurrentView('login');
    showToast('Signed out successfully', 'success');
  };

  // ==========================================
  // NOTES CRUD LOGIC
  // ==========================================

  const fetchNotes = async (page = 1, search = '') => {
    setIsLoading(true);
    try {
      let url = `/notes?page=${page}&limit=6`;
      if (selectedCategory !== 'all') {
        url += `&category=${selectedCategory}`;
      }
      if (search.trim()) {
        url += `&search=${encodeURIComponent(search)}`;
      }
      const res = await api.get(url);
      const { data, pagination } = res.data;
      
      setNotes(data || []);
      setCurrentPage(pagination?.current_page || 1);
      setLastPage(pagination?.last_page || 1);
      setTotalNotes(pagination?.total || 0);
    } catch (err: any) {
      showToast('Error loading notes from MySQL', 'danger');
    } finally {
      setIsLoading(false);
    }
  };

  const handleSearchSubmit = (e: FormEvent) => {
    e.preventDefault();
    setCurrentPage(1);
    if (isSemantic) {
      performSemanticSearch();
    } else {
      fetchNotes(1, searchQuery);
    }
  };

  const performSemanticSearch = async () => {
    if (!searchQuery.trim()) {
      fetchNotes(1, '');
      return;
    }
    setIsLoading(true);
    try {
      const res = await api.get(`/search?q=${encodeURIComponent(searchQuery)}`);
      // Semantic search returns a ranked note list
      setNotes(res.data || []);
      setCurrentPage(1);
      setLastPage(1);
      setTotalNotes(res.data.length || 0);
      showToast(`AI found ${res.data.length} conceptual matches`, 'success');
    } catch (err: any) {
      showToast('Semantic search query failed', 'danger');
    } finally {
      setIsLoading(false);
    }
  };

  const handleOpenCreate = () => {
    setEditingNote(null);
    setNoteTitle('');
    setNoteContent('');
    setNoteCategory('work');
    setIsEditorOpen(true);
  };

  const handleOpenEdit = (note: Note) => {
    setEditingNote(note);
    setNoteTitle(note.title);
    setNoteContent(note.content);
    setNoteCategory(note.category || 'work');
    setIsEditorOpen(true);
  };

  const handleSaveNote = async () => {
    if (!noteTitle.trim() || !noteContent.trim()) {
      showToast('Title and content cannot be blank', 'warning');
      return;
    }
    setIsSaving(true);
    try {
      const isEdit = !!editingNote;
      if (isEdit) {
        await api.put(`/notes/${editingNote.id}`, {
          title: noteTitle,
          content: noteContent,
          category: noteCategory
        });
        showToast('Note updated and Qdrant index refreshed', 'success');
      } else {
        await api.post('/notes', {
          title: noteTitle,
          content: noteContent,
          category: noteCategory
        });
        showToast('Note created and Qdrant index updated', 'success');
      }

      setIsEditorOpen(false);
      
      if (isSemantic) {
        performSemanticSearch();
      } else {
        fetchNotes(currentPage, searchQuery);
      }
    } catch (err: any) {
      showToast(err.response?.data?.message || 'Failed to save note', 'danger');
    } finally {
      setIsSaving(false);
    }
  };

  const handleDeleteNote = async (id: number, e: React.MouseEvent) => {
    e.stopPropagation();
    if (!confirm('Are you sure you want to delete this note from MySQL and Qdrant?')) return;
    
    try {
      await api.delete(`/notes/${id}`);
      showToast('Note deleted successfully', 'success');
      if (isSemantic) {
        performSemanticSearch();
      } else {
        fetchNotes(currentPage, searchQuery);
      }
    } catch (err: any) {
      showToast('Failed to delete note from server', 'danger');
    }
  };

  const handleGenerateSummary = async () => {
    if (!editingNote || !editingNote.id) return;
    setIsSummarizing(true);
    try {
      const res = await api.post(`/notes/${editingNote.id}/summary`);
      const { summary } = res.data;
      
      setEditingNote(prev => prev ? { ...prev, summary } : null);
      showToast('AI note summary generated', 'success');
      
      // Sync local notes state
      setNotes(prev => prev.map(n => n.id === editingNote.id ? { ...n, summary } : n));
    } catch (err: any) {
      showToast('Gemini API summary calculation failed', 'danger');
    } finally {
      setIsSummarizing(false);
    }
  };

  // ==========================================
  // RENDER BLOCKS
  // ==========================================

  // AUTH VIEW (LOGIN / REGISTER)
  if (currentView === 'login' || currentView === 'register') {
    return (
      <div className="flex min-h-screen items-center justify-center relative px-4">
        {/* Background Visual Effects */}
        <div className="bg-blobs">
          <div className="blob blob-1"></div>
          <div className="blob blob-2"></div>
        </div>

        <div className="modal-content w-full max-w-md p-8 border border-white/20">
          <div className="flex flex-col items-center mb-6">
            <h2 className="text-3xl font-bold tracking-tight text-center bg-gradient-to-r from-indigo-500 to-purple-600 bg-clip-text text-transparent font-heading">
              MindFlow AI
            </h2>
            <p className="text-sm text-gray-500 mt-1">SaaS AI Note Management System</p>
          </div>

          {authError && (
            <div className="p-3 mb-4 text-xs font-semibold text-red-500 bg-red-500/10 rounded-md border border-red-500/20 text-center">
              {authError}
            </div>
          )}

          {currentView === 'login' ? (
            <form onSubmit={handleLogin} className="space-y-4">
              <div className="form-group">
                <label className="form-label text-xs">EMAIL ADDRESS</label>
                <input 
                  type="email" 
                  className="form-input text-sm" 
                  placeholder="name@company.com"
                  value={authEmail}
                  onChange={(e) => setAuthEmail(e.target.value)}
                  required
                />
              </div>
              <div className="form-group">
                <label className="form-label text-xs">PASSWORD</label>
                <input 
                  type="password" 
                  className="form-input text-sm" 
                  placeholder="••••••••"
                  value={authPassword}
                  onChange={(e) => setAuthPassword(e.target.value)}
                  required
                />
              </div>
              <button type="submit" className="w-full btn btn-primary mt-2" disabled={isSaving}>
                {isSaving ? <span className="loader mr-2"></span> : null}
                Sign In to Account
              </button>
              <div className="text-center mt-4">
                <p className="text-xs text-gray-500">
                  Don't have an account?{' '}
                  <button 
                    type="button" 
                    className="text-indigo-500 font-semibold hover:underline"
                    onClick={() => {
                      setCurrentView('register');
                      setAuthError('');
                    }}
                  >
                    Register Here
                  </button>
                </p>
              </div>
            </form>
          ) : (
            <form onSubmit={handleRegister} className="space-y-4">
              <div className="form-group">
                <label className="form-label text-xs">FULL NAME</label>
                <input 
                  type="text" 
                  className="form-input text-sm" 
                  placeholder="Jane Doe"
                  value={authName}
                  onChange={(e) => setAuthName(e.target.value)}
                  required
                />
              </div>
              <div className="form-group">
                <label className="form-label text-xs">EMAIL ADDRESS</label>
                <input 
                  type="email" 
                  className="form-input text-sm" 
                  placeholder="jane@company.com"
                  value={authEmail}
                  onChange={(e) => setAuthEmail(e.target.value)}
                  required
                />
              </div>
              <div className="form-group">
                <label className="form-label text-xs">PASSWORD</label>
                <input 
                  type="password" 
                  className="form-input text-sm" 
                  placeholder="Min. 8 characters"
                  value={authPassword}
                  onChange={(e) => setAuthPassword(e.target.value)}
                  required
                />
              </div>
              <div className="form-group">
                <label className="form-label text-xs">CONFIRM PASSWORD</label>
                <input 
                  type="password" 
                  className="form-input text-sm" 
                  placeholder="Re-enter password"
                  value={authConfirmPassword}
                  onChange={(e) => setAuthConfirmPassword(e.target.value)}
                  required
                />
              </div>
              <button type="submit" className="w-full btn btn-primary mt-2" disabled={isSaving}>
                {isSaving ? <span className="loader mr-2"></span> : null}
                Create Account
              </button>
              <div className="text-center mt-4">
                <p className="text-xs text-gray-500">
                  Already registered?{' '}
                  <button 
                    type="button" 
                    className="text-indigo-500 font-semibold hover:underline"
                    onClick={() => {
                      setCurrentView('login');
                      setAuthError('');
                    }}
                  >
                    Login here
                  </button>
                </p>
              </div>
            </form>
          )}
        </div>
      </div>
    );
  }

  // DASHBOARD MAIN SAAS VIEW
  return (
    <>
      <div className="bg-blobs">
        <div className="blob blob-1"></div>
        <div className="blob blob-2"></div>
      </div>

      <div className="flex min-h-screen">
        {/* SIDEBAR: SaaS Left Panel */}
        <aside className="w-64 bg-slate-900/60 backdrop-blur-xl border-r border-white/5 flex flex-col p-6 text-white shrink-0">
          <div className="logo mb-8 font-bold text-xl flex items-center gap-2">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 3v19" />
              <path d="M5 12h14" />
              <path d="m16 5 4 4-4 4" />
            </svg>
            <span className="font-heading">MindFlow AI</span>
          </div>

          <div className="flex items-center gap-3 p-3 bg-white/5 rounded-xl border border-white/10 mb-8">
            <div className="w-9 h-9 rounded-full bg-indigo-500 flex items-center justify-center font-bold text-sm">
              {user?.name.charAt(0).toUpperCase() || 'U'}
            </div>
            <div className="overflow-hidden">
              <h4 className="text-xs font-semibold truncate leading-none mb-1">{user?.name}</h4>
              <span className="text-[10px] text-gray-400 truncate block leading-none">{user?.email}</span>
            </div>
          </div>

          <nav className="flex-1 space-y-2 text-sm font-semibold">
            <button 
              className={`w-full flex items-center justify-between p-2.5 rounded-lg transition text-left ${
                selectedCategory === 'all' 
                  ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' 
                  : 'hover:bg-white/5 text-gray-400 hover:text-white'
              }`}
              onClick={() => {
                setSelectedCategory('all');
                setCurrentPage(1);
              }}
            >
              <span className="flex items-center gap-3">🗂️ All Notes</span>
              <span className="bg-indigo-500 text-white text-[10px] px-2 py-0.5 rounded-full">{totalNotes}</span>
            </button>
            <button 
              className={`w-full flex items-center gap-3 p-2.5 rounded-lg transition text-left ${
                selectedCategory === 'work' 
                  ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' 
                  : 'hover:bg-white/5 text-gray-400 hover:text-white'
              }`}
              onClick={() => {
                setSelectedCategory('work');
                setCurrentPage(1);
              }}
            >
              <span>📁 Work Project</span>
            </button>
            <button 
              className={`w-full flex items-center gap-3 p-2.5 rounded-lg transition text-left ${
                selectedCategory === 'personal' 
                  ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' 
                  : 'hover:bg-white/5 text-gray-400 hover:text-white'
              }`}
              onClick={() => {
                setSelectedCategory('personal');
                setCurrentPage(1);
              }}
            >
              <span>📁 Personal Log</span>
            </button>
            <button 
              className={`w-full flex items-center gap-3 p-2.5 rounded-lg transition text-left ${
                selectedCategory === 'ideas' 
                  ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' 
                  : 'hover:bg-white/5 text-gray-400 hover:text-white'
              }`}
              onClick={() => {
                setSelectedCategory('ideas');
                setCurrentPage(1);
              }}
            >
              <span>💡 Brainstorm Ideas</span>
            </button>
          </nav>

          <div className="pt-6 border-t border-white/5">
            <button className="w-full btn btn-secondary text-red-500 hover:bg-red-500/10 border-red-500/20" onClick={handleLogout}>
              Sign Out Account
            </button>
          </div>
        </aside>

        {/* MAIN BODY AREA */}
        <main className="flex-1 p-8 overflow-y-auto">
          {/* NAVBAR: Top Header */}
          <header className="flex justify-between items-center mb-8 bg-white/5 backdrop-blur-xl border border-white/10 p-4 rounded-2xl shadow-sm">
            <div>
              <h2 className="text-xl font-bold tracking-tight font-heading">Workspace notes</h2>
              <p className="text-xs text-gray-500">Secure Eloquent + MySQL + Qdrant similarity indexes</p>
            </div>
            <div className="flex items-center gap-4">
              <button className="btn-icon" onClick={toggleTheme} title="Toggle Dark/Light Mode">
                {theme === 'light' ? '🌙' : '🌞'}
              </button>
              <button className="btn btn-primary" onClick={handleOpenCreate}>
                Create note
              </button>
            </div>
          </header>

          {/* SEARCH BAR PANEL */}
          <section className="search-container">
            <div className="search-header">
              <h3 className="font-heading font-semibold text-md">Search and Concepts Filter</h3>
              <div className="search-toggle">
                <button 
                  type="button" 
                  className={`toggle-option ${!isSemantic ? 'active' : ''}`}
                  onClick={() => {
                    setIsSemantic(false);
                    setCurrentPage(1);
                  }}
                >
                  Standard Search
                </button>
                <button 
                  type="button" 
                  className={`toggle-option ${isSemantic ? 'active' : ''}`}
                  onClick={() => {
                    setIsSemantic(true);
                    setCurrentPage(1);
                  }}
                >
                  ✨ AI Semantic Search
                </button>
              </div>
            </div>
            <form onSubmit={handleSearchSubmit} className="search-input-wrapper">
              <input 
                type="text" 
                className="search-input" 
                placeholder={isSemantic 
                  ? "Describe ideas, concepts, or details (e.g. 'how authentication works' or 'lasagna ingredients')" 
                  : "Search MySQL by note title or keyword..."
                }
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              <button type="submit" className="btn btn-primary px-6">
                Search
              </button>
            </form>
          </section>

          {/* NOTES GRID DISPLAY */}
          {isLoading ? (
            <div className="notes-grid">
              <div className="shimmer-card">
                <div className="shimmer-line shimmer-title"></div>
                <div className="shimmer-line shimmer-meta"></div>
                <div className="shimmer-line shimmer-content"></div>
              </div>
              <div className="shimmer-card">
                <div className="shimmer-line shimmer-title"></div>
                <div className="shimmer-line shimmer-meta"></div>
                <div className="shimmer-line shimmer-content"></div>
              </div>
              <div className="shimmer-card">
                <div className="shimmer-line shimmer-title"></div>
                <div className="shimmer-line shimmer-meta"></div>
                <div className="shimmer-line shimmer-content"></div>
              </div>
            </div>
          ) : notes.length === 0 ? (
            <div className="flex flex-col items-center justify-center p-12 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
              <p className="text-sm text-gray-500 mb-4">No notes found. Create one to get started.</p>
              <button className="btn btn-primary" onClick={handleOpenCreate}>Add first note</button>
            </div>
          ) : (
            <>
              <div className="notes-grid">
                {notes.map(note => (
                  <div key={note.id} className="note-card" onClick={() => handleOpenEdit(note)}>
                    <div className="note-header">
                      <h4 className="note-title font-semibold text-lg">{note.title}</h4>
                      {note.summary && (
                        <span className="tag-chip font-bold text-[9px] uppercase tracking-wider" style={{
                          background: 'linear-gradient(135deg, rgba(99,102,241,0.2) 0%, rgba(168,85,247,0.2) 100%)',
                          color: 'var(--text-secondary)'
                        }}>
                          ✨ AI Summary
                        </span>
                      )}
                    </div>
                    <div className="note-meta text-xs text-gray-500 mb-2">
                      {new Date(note.created_at).toLocaleDateString(undefined, { 
                        month: 'short', 
                        day: 'numeric', 
                        year: 'numeric' 
                      })}
                    </div>
                    <p className="note-content-preview text-sm text-gray-400 line-clamp-4 mb-4 flex-1">{note.content}</p>
                    
                    <div className="note-footer flex justify-between items-center pt-3 border-t border-white/5">
                      <span className="text-xs text-indigo-400 font-semibold cursor-pointer">Open Details</span>
                      <div className="card-actions flex gap-2">
                        <button 
                          className="btn-icon w-8 h-8 text-xs text-red-500 hover:bg-red-500/10 border-red-500/10" 
                          onClick={(e) => handleDeleteNote(note.id, e)}
                          title="Delete Note"
                        >
                          🗑️
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              {/* PAGINATION PANEL */}
              {!isSemantic && lastPage > 1 && (
                <div className="pagination">
                  <button 
                    className="page-btn" 
                    disabled={currentPage === 1}
                    onClick={() => setCurrentPage(prev => prev - 1)}
                  >
                    Previous
                  </button>
                  {Array.from({ length: lastPage }, (_, i) => i + 1).map(pageNum => (
                    <button 
                      key={pageNum}
                      className={`page-btn ${currentPage === pageNum ? 'active' : ''}`}
                      onClick={() => setCurrentPage(pageNum)}
                    >
                      {pageNum}
                    </button>
                  ))}
                  <button 
                    className="page-btn" 
                    disabled={currentPage === lastPage}
                    onClick={() => setCurrentPage(prev => prev + 1)}
                  >
                    Next
                  </button>
                </div>
              )}
            </>
          )}
        </main>
      </div>

      {/* CREATE / EDIT NOTE FORM MODAL */}
      {isEditorOpen && (
        <div className="modal-overlay" onClick={() => setIsEditorOpen(false)}>
          <div className="modal-content max-w-2xl border border-white/10" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3 className="text-lg font-bold font-heading">{editingNote ? 'Edit note details' : 'Write new note'}</h3>
              <button className="btn-icon" onClick={() => setIsEditorOpen(false)}>✕</button>
            </div>

            <div className="form-group mb-4">
              <label className="form-label text-xs">TITLE</label>
              <input 
                type="text" 
                className="form-input text-sm" 
                value={noteTitle} 
                onChange={(e) => setNoteTitle(e.target.value)}
                placeholder="Database configuration..."
              />
            </div>

            <div className="form-group mb-4">
              <label className="form-label text-xs">FOLDER CATEGORY</label>
              <select 
                className="form-input text-sm" 
                value={noteCategory} 
                onChange={(e) => setNoteCategory(e.target.value)}
                style={{ backgroundColor: 'var(--bg-card)', color: 'var(--text-primary)' }}
              >
                <option value="work">💼 Work Project</option>
                <option value="personal">🔒 Personal Log</option>
                <option value="ideas">💡 Brainstorm Ideas</option>
              </select>
            </div>

            <div className="form-group mb-4">
              <label className="form-label text-xs">CONTENT BODY</label>
              <textarea 
                className="form-textarea text-sm h-48"
                value={noteContent}
                onChange={(e) => setNoteContent(e.target.value)}
                placeholder="Start typing your concepts or code snippets..."
              />
            </div>

            {/* AI Summary Block */}
            {editingNote && (
              <div className="ai-summary-box mt-4 p-4 rounded-xl border border-dashed border-indigo-500/40 bg-indigo-500/5">
                <div className="ai-summary-header flex items-center justify-between mb-2">
                  <div className="flex items-center gap-1.5 text-indigo-400 font-bold text-xs">
                    <span>✨ AI Summary Insight</span>
                  </div>
                  <button 
                    className="btn btn-secondary py-1 px-3 text-xs bg-white/5 border-white/10 hover:bg-white/10"
                    onClick={handleGenerateSummary}
                    disabled={isSummarizing}
                  >
                    {isSummarizing ? (
                      <>
                        <span className="loader mr-1"></span>
                        Working...
                      </>
                    ) : (
                      editingNote.summary ? 'Regenerate Summary' : 'Generate Summary'
                    )}
                  </button>
                </div>
                <div className="ai-summary-content text-sm leading-relaxed text-gray-300">
                  {editingNote.summary ? (
                    <p className="whitespace-pre-line">{editingNote.summary}</p>
                  ) : (
                    <p className="text-xs text-gray-500 italic">No summary exists. Click the button to summarize.</p>
                  )}
                </div>
              </div>
            )}

            <div className="modal-actions mt-6 flex justify-end gap-3">
              <button className="btn btn-secondary" onClick={() => setIsEditorOpen(false)} disabled={isSaving}>Cancel</button>
              <button className="btn btn-primary" onClick={handleSaveNote} disabled={isSaving}>
                {isSaving ? (
                  <>
                    <span className="loader mr-1"></span>
                    Saving...
                  </>
                ) : (
                  'Save Note'
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* TOAST LIST */}
      <div className="toast-container">
        {toasts.map(toast => (
          <div key={toast.id} className={`toast ${toast.type} font-semibold text-xs border-l-4`}>
            <span>{toast.message}</span>
          </div>
        ))}
      </div>
    </>
  );
}

export default App;
