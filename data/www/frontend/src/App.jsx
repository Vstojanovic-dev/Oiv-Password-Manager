import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  Copy,
  DatabaseBackup,
  Edit3,
  Eye,
  EyeOff,
  History,
  KeyRound,
  LogOut,
  Menu,
  Plus,
  RefreshCw,
  Search,
  Settings,
  Shield,
  Star,
  Trash2,
  Upload,
  User,
  Wand2,
  X,
} from 'lucide-react';
import { api } from './api.js';

const DEFAULT_ENTRY_FORM = {
  site_name: '',
  username: '',
  password: '',
  site_url: '',
  category: '',
  favorite: false,
  notes: '',
};

const DEFAULT_GENERATOR = {
  length: 20,
  uppercase: true,
  lowercase: true,
  numbers: true,
  symbols: true,
};

const navItems = [
  { id: 'vault', label: 'Vault', icon: KeyRound },
  { id: 'generator', label: 'Generator', icon: Wand2 },
  { id: 'backup', label: 'Backup', icon: DatabaseBackup },
  { id: 'recovery', label: 'Recovery', icon: Shield },
  { id: 'security', label: 'Security', icon: History },
  { id: 'settings', label: 'Settings', icon: Settings },
];

export default function App() {
  const [checkingSession, setCheckingSession] = useState(true);
  const [authenticated, setAuthenticated] = useState(false);
  const [username, setUsername] = useState('');
  const [status, setStatus] = useState(null);
  const [authMode, setAuthMode] = useState('login');
  const [section, setSection] = useState('vault');
  const [notice, setNotice] = useState(null);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  const [authForm, setAuthForm] = useState({ username: '', password: '' });
  const [forgotForm, setForgotForm] = useState({ username: '', recovery_code: '' });
  const [resetToken, setResetToken] = useState('');
  const [resetPassword, setResetPassword] = useState('');
  const [authLoading, setAuthLoading] = useState(false);

  const [accounts, setAccounts] = useState([]);
  const [accountsLoading, setAccountsLoading] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [favoritesOnly, setFavoritesOnly] = useState(false);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [trashMode, setTrashMode] = useState(false);
  const [revealed, setRevealed] = useState({});
  const [entryModal, setEntryModal] = useState({ open: false, mode: 'create', id: null });
  const [entryForm, setEntryForm] = useState(DEFAULT_ENTRY_FORM);
  const [entrySaving, setEntrySaving] = useState(false);
  const [lastPasswordAssessment, setLastPasswordAssessment] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [historyTarget, setHistoryTarget] = useState(null);
  const [passwordHistory, setPasswordHistory] = useState([]);

  const [generatorForm, setGeneratorForm] = useState(DEFAULT_GENERATOR);
  const [generatedPassword, setGeneratedPassword] = useState('');
  const [generatorLoading, setGeneratorLoading] = useState(false);

  const [backupPassword, setBackupPassword] = useState('');
  const [restoreFile, setRestoreFile] = useState(null);
  const [backupHistory, setBackupHistory] = useState([]);
  const [backupLoading, setBackupLoading] = useState(false);

  const [recoveryPassword, setRecoveryPassword] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState([]);

  const [securityEvents, setSecurityEvents] = useState([]);
  const [passwordReport, setPasswordReport] = useState(null);
  const [securityLoading, setSecurityLoading] = useState(false);

  const [passwordForm, setPasswordForm] = useState({ current_password: '', new_password: '' });
  const [sessions, setSessions] = useState([]);
  const [settingsLoading, setSettingsLoading] = useState(false);

  const showNotice = useCallback((text, type = 'info') => {
    setNotice({ text, type });
  }, []);

  const refreshStatus = useCallback(async () => {
    const res = await api('/auth/status');
    if (res.success && res.data?.authenticated) {
      setAuthenticated(true);
      setUsername(res.data.username || '');
      setStatus(res.data);
    } else {
      setAuthenticated(false);
      setUsername('');
      setStatus(res.data || null);
    }
    return res;
  }, []);

  const loadAccounts = useCallback(async (term = searchTerm) => {
    setAccountsLoading(true);
    try {
      const params = new URLSearchParams();
      if (term.trim()) params.set('search', term.trim());
      if (favoritesOnly) params.set('favorite', '1');
      if (categoryFilter.trim()) params.set('category', categoryFilter.trim());
      const query = params.toString() ? `?${params.toString()}` : '';
      const res = await api(trashMode ? '/accounts/trash' : `/accounts${query}`);
      if (res.success) {
        setAccounts(res.data || []);
        setRevealed({});
      } else {
        showNotice(res.error || 'Failed to load vault entries.', 'error');
      }
    } catch (error) {
      showNotice(error.message || 'Failed to load vault entries.', 'error');
    } finally {
      setAccountsLoading(false);
    }
  }, [categoryFilter, favoritesOnly, searchTerm, showNotice, trashMode]);

  const loadBackupHistory = useCallback(async () => {
    const res = await api('/backup/history');
    if (res.success) setBackupHistory(res.data || []);
    else showNotice(res.error || 'Failed to load backup history.', 'error');
  }, [showNotice]);

  const loadSecurityEvents = useCallback(async () => {
    setSecurityLoading(true);
    try {
      const [eventsRes, reportRes] = await Promise.all([
        api('/auth/security-events'),
        api('/accounts/password-report'),
      ]);
      if (eventsRes.success) setSecurityEvents(eventsRes.data || []);
      else showNotice(eventsRes.error || 'Failed to load security events.', 'error');
      if (reportRes.success) setPasswordReport(reportRes.data || null);
      else showNotice(reportRes.error || 'Failed to load password report.', 'error');
    } finally {
      setSecurityLoading(false);
    }
  }, [showNotice]);

  const loadSessions = useCallback(async () => {
    const res = await api('/auth/sessions');
    if (res.success) setSessions(res.data || []);
    else showNotice(res.error || 'Failed to load sessions.', 'error');
  }, [showNotice]);

  useEffect(() => {
    refreshStatus()
      .catch(() => setAuthenticated(false))
      .finally(() => setCheckingSession(false));
  }, [refreshStatus]);

  useEffect(() => {
    if (!authenticated) return;
    if (section === 'vault') loadAccounts();
    if (section === 'backup') loadBackupHistory();
    if (section === 'security') loadSecurityEvents();
    if (section === 'settings') loadSessions();
  }, [authenticated, section, loadAccounts, loadBackupHistory, loadSecurityEvents, loadSessions]);

  const visibleAccounts = useMemo(() => {
    return accounts;
  }, [accounts]);

  const accountStats = useMemo(() => ({
    total: accounts.length,
    favorites: accounts.filter((account) => Boolean(account.favorite)).length,
    categories: new Set(accounts.map((account) => account.category).filter(Boolean)).size,
  }), [accounts]);

  function updateAuthForm(field, value) {
    setAuthForm((current) => ({ ...current, [field]: value }));
  }

  function resetEntryForm() {
    setEntryForm(DEFAULT_ENTRY_FORM);
    setEntryModal({ open: false, mode: 'create', id: null });
  }

  function openCreateEntry(prefilledPassword = '') {
    setLastPasswordAssessment(null);
    setEntryForm({ ...DEFAULT_ENTRY_FORM, password: prefilledPassword });
    setEntryModal({ open: true, mode: 'create', id: null });
  }

  async function fetchAccountPassword(accountId) {
    const res = await api(`/accounts/${accountId}/password`);
    if (!res.success) {
      throw new Error(res.error || 'Could not load password.');
    }
    return res.data?.password || '';
  }

  async function openEditEntry(account) {
    setLastPasswordAssessment(null);
    try {
      const password = await fetchAccountPassword(account.id);
      setEntryForm({
        site_name: account.site_name || '',
        username: account.username || '',
        password,
        site_url: account.site_url || '',
        category: account.category || '',
        favorite: Boolean(account.favorite),
        notes: account.notes || '',
      });
      setEntryModal({ open: true, mode: 'edit', id: account.id });
    } catch (error) {
      showNotice(error.message || 'Could not load entry password.', 'error');
    }
  }

  async function handleLogin(event) {
    event.preventDefault();
    setAuthLoading(true);
    try {
      const res = await api('/auth/login', {
        method: 'POST',
        body: authForm,
      });
      if (res.success) {
        setAuthenticated(true);
        setUsername(res.data?.username || authForm.username);
        setStatus({ authenticated: true, username: res.data?.username || authForm.username });
        setSection('vault');
        showNotice(res.data?.message || 'Login successful.', 'success');
      } else {
        showNotice(res.error || 'Login failed.', 'error');
      }
    } catch (error) {
      showNotice(error.message || 'Login failed.', 'error');
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleRegister(event) {
    event.preventDefault();
    setAuthLoading(true);
    try {
      const res = await api('/auth/register', {
        method: 'POST',
        body: authForm,
      });
      if (!res.success) {
        showNotice(res.error || 'Registration failed.', 'error');
        return;
      }

      setAuthenticated(true);
      setUsername(res.data?.username || authForm.username);
      const codesRes = await api('/auth/recovery-codes', {
        method: 'POST',
        body: { current_password: authForm.password },
      });
      if (codesRes.success) {
        setRecoveryCodes(codesRes.data?.codes || []);
        setSection('recovery');
        showNotice('Account created. Store these recovery codes now.', 'success');
      } else {
        setSection('vault');
        showNotice(`Account created, but recovery codes were not generated: ${codesRes.error || 'unknown error'}`, 'error');
      }
    } catch (error) {
      showNotice(error.message || 'Registration failed.', 'error');
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleForgotVerify(event) {
    event.preventDefault();
    const res = await api('/auth/forgot/verify', {
      method: 'POST',
      body: forgotForm,
    });
    if (res.success) {
      setResetToken(res.data?.reset_token || '');
      setAuthMode('reset');
      setForgotForm((current) => ({ ...current, recovery_code: '' }));
      showNotice(res.data?.message || 'Recovery code accepted.', 'success');
    } else {
      showNotice(res.error || 'Invalid recovery code.', 'error');
    }
  }

  async function handleForgotReset(event) {
    event.preventDefault();
    const res = await api('/auth/forgot/reset', {
      method: 'POST',
      body: { reset_token: resetToken, new_password: resetPassword },
    });
    if (res.success) {
      setAuthenticated(true);
      setUsername(res.data?.username || forgotForm.username);
      setResetToken('');
      setResetPassword('');
      setSection('vault');
      setAuthMode('login');
      showNotice(res.data?.message || 'Password reset. Vault preserved.', 'success');
    } else {
      showNotice(res.error || 'Password reset failed.', 'error');
    }
  }

  async function handleLogout() {
    const res = await api('/auth/logout', { method: 'POST' });
    if (res.success) {
      setAuthenticated(false);
      setUsername('');
      setAccounts([]);
      setSessions([]);
      setRevealed({});
      setAuthMode('login');
      showNotice(res.data?.message || 'Logged out.', 'success');
    } else {
      showNotice(res.error || 'Logout failed.', 'error');
    }
  }

  async function handleLogoutAll() {
    setSettingsLoading(true);
    try {
      const res = await api('/auth/logout-all', { method: 'POST' });
      if (res.success) {
        setAuthenticated(false);
        setUsername('');
        setAccounts([]);
        setSessions([]);
        setRevealed({});
        setAuthMode('login');
        showNotice(res.data?.message || 'All sessions logged out.', 'success');
      } else {
        showNotice(res.error || 'Logout all failed.', 'error');
      }
    } finally {
      setSettingsLoading(false);
    }
  }

  async function handleSearch(event) {
    event.preventDefault();
    await loadAccounts(searchTerm);
  }

  async function saveEntry(event) {
    event.preventDefault();
    setEntrySaving(true);
    try {
      const payload = {
        site_name: entryForm.site_name,
        username: entryForm.username,
        password: entryForm.password,
        site_url: entryForm.site_url || undefined,
        category: entryForm.category || undefined,
        favorite: entryForm.favorite,
        notes: entryForm.notes || undefined,
      };
      const isEdit = entryModal.mode === 'edit';
      const res = await api(isEdit ? `/accounts/${entryModal.id}` : '/accounts', {
        method: isEdit ? 'PUT' : 'POST',
        body: payload,
      });
      if (res.success) {
        const { password: _ignoredPassword, ...account } = res.data;
        setAccounts((current) => {
          if (isEdit) {
            return current.map((item) => (Number(item.id) === Number(account.id) ? account : item));
          }
          return [...current, account].sort((a, b) => String(a.site_name).localeCompare(String(b.site_name)));
        });
        setLastPasswordAssessment({
          strength: account.password_strength,
          warnings: account.password_warnings || [],
        });
        setRevealed((current) => {
          const next = { ...current };
          delete next[account.id];
          return next;
        });
        resetEntryForm();
        const warnings = account.password_warnings || [];
        showNotice(warnings.length ? `Entry saved. Warning: ${warnings.join(' ')}` : 'Entry saved.', warnings.length ? 'info' : 'success');
      } else {
        showNotice(res.error || 'Entry save failed.', 'error');
      }
    } finally {
      setEntrySaving(false);
    }
  }

  async function deleteEntry() {
    if (!deleteTarget) return;
    const res = await api(`/accounts/${deleteTarget.id}`, { method: 'DELETE' });
    if (res.success) {
      setAccounts((current) => current.filter((account) => Number(account.id) !== Number(deleteTarget.id)));
      setRevealed((current) => {
        const next = { ...current };
        delete next[deleteTarget.id];
        return next;
      });
      setDeleteTarget(null);
      showNotice('Entry moved to trash.', 'success');
    } else {
      showNotice(res.error || 'Delete failed.', 'error');
    }
  }

  async function restoreEntry(account) {
    const res = await api(`/accounts/${account.id}/restore`, { method: 'POST' });
    if (res.success) {
      setAccounts((current) => current.filter((item) => Number(item.id) !== Number(account.id)));
      showNotice('Entry restored.', 'success');
    } else {
      showNotice(res.error || 'Restore failed.', 'error');
    }
  }

  async function permanentDeleteEntry(account) {
    const res = await api(`/accounts/${account.id}/permanent`, { method: 'DELETE' });
    if (res.success) {
      setAccounts((current) => current.filter((item) => Number(item.id) !== Number(account.id)));
      showNotice('Entry permanently deleted.', 'success');
    } else {
      showNotice(res.error || 'Permanent delete failed.', 'error');
    }
  }

  async function loadPasswordHistory(account) {
    const res = await api(`/accounts/${account.id}/history`);
    if (res.success) {
      setHistoryTarget(account);
      setPasswordHistory(res.data || []);
    } else {
      showNotice(res.error || 'Could not load password history.', 'error');
    }
  }

  async function copyText(value, label, account = null) {
    try {
      if (!navigator.clipboard) throw new Error('Clipboard is not available.');
      await navigator.clipboard.writeText(value || '');
      if (label === 'password' && account) {
        const res = await api(`/accounts/${account.id}/used`, { method: 'POST' });
        if (res.success) {
          setAccounts((current) => current.map((item) => (
            Number(item.id) === Number(account.id) ? res.data : item
          )));
        }
      }
      showNotice(`${label} copied.`, 'success');
    } catch (error) {
      showNotice(error.message || `Could not copy ${label}.`, 'error');
    }
  }

  async function copyAccountPassword(account) {
    try {
      if (!navigator.clipboard) throw new Error('Clipboard is not available.');
      const password = await fetchAccountPassword(account.id);
      await navigator.clipboard.writeText(password);
      const res = await api(`/accounts/${account.id}/used`, { method: 'POST' });
      if (res.success) {
        setAccounts((current) => current.map((item) => (
          Number(item.id) === Number(account.id) ? res.data : item
        )));
      }
      showNotice('password copied.', 'success');
    } catch (error) {
      showNotice(error.message || 'Could not copy password.', 'error');
    }
  }

  async function toggleRevealPassword(account) {
    if (revealed[account.id]) {
      setRevealed((current) => {
        const next = { ...current };
        delete next[account.id];
        return next;
      });
      return;
    }

    try {
      const password = await fetchAccountPassword(account.id);
      setRevealed((current) => ({ ...current, [account.id]: password }));
    } catch (error) {
      showNotice(error.message || 'Could not reveal password.', 'error');
    }
  }

  async function generatePassword(event) {
    event?.preventDefault();
    setGeneratorLoading(true);
    try {
      const res = await api('/generator/password', {
        method: 'POST',
        body: generatorForm,
      });
      if (res.success) {
        setGeneratedPassword(res.data?.password || '');
        showNotice('Password generated.', 'success');
      } else {
        showNotice(res.error || 'Password generation failed.', 'error');
      }
    } finally {
      setGeneratorLoading(false);
    }
  }

  async function createBackup() {
    setBackupLoading(true);
    try {
      const res = await api('/backup', {
        method: 'POST',
        body: { backup_password: backupPassword },
      });
      if (res.success) {
        showNotice(`${res.data?.message || 'Backup created.'} File: ${res.data?.filename || 'unknown'}`, 'success');
        await loadBackupHistory();
      } else {
        showNotice(res.error || 'Backup failed.', 'error');
      }
    } finally {
      setBackupLoading(false);
    }
  }

  async function restoreBackup(event) {
    event.preventDefault();
    if (!restoreFile) {
      showNotice('Choose a backup JSON file.', 'error');
      return;
    }
    setBackupLoading(true);
    try {
      const formData = new FormData();
      formData.append('backup_file', restoreFile);
      formData.append('backup_password', backupPassword);
      const res = await api('/backup/restore', { method: 'POST', body: formData, isForm: true });
      if (res.success) {
        showNotice(`Restore complete. Imported: ${res.data?.imported ?? 0}, skipped: ${res.data?.skipped ?? 0}.`, 'success');
        setRestoreFile(null);
        await loadAccounts();
        await loadBackupHistory();
      } else {
        showNotice(res.error || 'Restore failed.', 'error');
      }
    } finally {
      setBackupLoading(false);
    }
  }

  async function generateRecoveryCodes(event) {
    event.preventDefault();
    const res = await api('/auth/recovery-codes', {
      method: 'POST',
      body: { current_password: recoveryPassword },
    });
    if (res.success) {
      setRecoveryCodes(res.data?.codes || []);
      setRecoveryPassword('');
      showNotice(res.data?.message || 'Recovery codes generated.', 'success');
    } else {
      showNotice(res.error || 'Could not generate recovery codes.', 'error');
    }
  }

  async function changeMasterPassword(event) {
    event.preventDefault();
    setSettingsLoading(true);
    try {
      const res = await api('/auth/password', {
        method: 'POST',
        body: passwordForm,
      });
      if (res.success) {
        setPasswordForm({ current_password: '', new_password: '' });
        showNotice(res.data?.message || 'Master password updated.', 'success');
      } else {
        showNotice(res.error || 'Password change failed.', 'error');
      }
    } finally {
      setSettingsLoading(false);
    }
  }

  async function revokeSession(session) {
    const res = await api(`/auth/sessions/${session.id}`, { method: 'DELETE' });
    if (res.success) {
      if (session.current) {
        setAuthenticated(false);
        setUsername('');
        setAccounts([]);
        setRevealed({});
        setAuthMode('login');
      } else {
        await loadSessions();
      }
      showNotice('Session revoked.', 'success');
    } else {
      showNotice(res.error || 'Could not revoke session.', 'error');
    }
  }

  if (checkingSession) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface">
        <div className="panel px-6 py-5 text-sm text-slate-600">Checking session...</div>
      </div>
    );
  }

  if (!authenticated) {
    return (
      <AuthLayout
        mode={authMode}
        setMode={setAuthMode}
        authForm={authForm}
        updateAuthForm={updateAuthForm}
        forgotForm={forgotForm}
        setForgotForm={setForgotForm}
        resetPassword={resetPassword}
        setResetPassword={setResetPassword}
        onLogin={handleLogin}
        onRegister={handleRegister}
        onForgotVerify={handleForgotVerify}
        onForgotReset={handleForgotReset}
        loading={authLoading}
        notice={notice}
      />
    );
  }

  return (
    <div className="min-h-screen bg-surface">
      <div className="flex min-h-screen">
        <Sidebar
          section={section}
          setSection={(next) => {
            setSection(next);
            setMobileNavOpen(false);
          }}
          open={mobileNavOpen}
          setOpen={setMobileNavOpen}
        />

        <main className="flex min-w-0 flex-1 flex-col">
          <TopBar
            username={username}
            status={status}
            notice={notice}
            onMenu={() => setMobileNavOpen(true)}
            onLogout={handleLogout}
            onRefreshStatus={refreshStatus}
          />

          <div className="flex-1 p-4 sm:p-6 lg:p-8">
            {section === 'vault' && (
              <VaultSection
                accounts={visibleAccounts}
                allStats={accountStats}
                loading={accountsLoading}
                searchTerm={searchTerm}
                setSearchTerm={setSearchTerm}
                favoritesOnly={favoritesOnly}
                setFavoritesOnly={setFavoritesOnly}
                categoryFilter={categoryFilter}
                setCategoryFilter={setCategoryFilter}
                trashMode={trashMode}
                setTrashMode={setTrashMode}
                revealed={revealed}
                onSearch={handleSearch}
                onReload={() => loadAccounts()}
                onCreate={() => openCreateEntry()}
                onEdit={openEditEntry}
                onDelete={setDeleteTarget}
                onCopy={copyText}
                onCopyPassword={copyAccountPassword}
                onReveal={toggleRevealPassword}
                onRestore={restoreEntry}
                onPermanentDelete={permanentDeleteEntry}
                onHistory={loadPasswordHistory}
              />
            )}

            {section === 'generator' && (
              <GeneratorSection
                form={generatorForm}
                setForm={setGeneratorForm}
                generatedPassword={generatedPassword}
                loading={generatorLoading}
                onGenerate={generatePassword}
                onCopy={(value) => copyText(value, 'generated password')}
                onUseInEntry={() => openCreateEntry(generatedPassword)}
              />
            )}

            {section === 'backup' && (
              <BackupSection
                backupPassword={backupPassword}
                setBackupPassword={setBackupPassword}
                restoreFile={restoreFile}
                setRestoreFile={setRestoreFile}
                history={backupHistory}
                loading={backupLoading}
                onCreate={createBackup}
                onRestore={restoreBackup}
                onReload={loadBackupHistory}
              />
            )}

            {section === 'recovery' && (
              <RecoverySection
                password={recoveryPassword}
                setPassword={setRecoveryPassword}
                codes={recoveryCodes}
                onGenerate={generateRecoveryCodes}
              />
            )}

            {section === 'security' && (
              <SecuritySection
                events={securityEvents}
                report={passwordReport}
                loading={securityLoading}
                onReload={loadSecurityEvents}
              />
            )}

            {section === 'settings' && (
              <SettingsSection
                username={username}
                status={status}
                passwordForm={passwordForm}
                setPasswordForm={setPasswordForm}
                loading={settingsLoading}
                sessions={sessions}
                onChangePassword={changeMasterPassword}
                onLogout={handleLogout}
                onLogoutAll={handleLogoutAll}
                onRefreshStatus={refreshStatus}
                onReloadSessions={loadSessions}
                onRevokeSession={revokeSession}
              />
            )}
          </div>
        </main>
      </div>

      {entryModal.open && (
        <EntryModal
          mode={entryModal.mode}
          form={entryForm}
          setForm={setEntryForm}
          saving={entrySaving}
          assessment={lastPasswordAssessment}
          onClose={resetEntryForm}
          onSubmit={saveEntry}
          onGenerate={async () => {
            const res = await api('/generator/password', { method: 'POST', body: DEFAULT_GENERATOR });
            if (res.success) setEntryForm((current) => ({ ...current, password: res.data?.password || '' }));
            else showNotice(res.error || 'Password generation failed.', 'error');
          }}
        />
      )}

      {deleteTarget && (
        <ConfirmModal
          title="Delete vault entry"
          body={`Move ${deleteTarget.site_name} to trash? You can restore it later from the trash view.`}
          confirmLabel="Move to trash"
          onCancel={() => setDeleteTarget(null)}
          onConfirm={deleteEntry}
        />
      )}

      {historyTarget && (
        <PasswordHistoryModal
          account={historyTarget}
          rows={passwordHistory}
          onClose={() => {
            setHistoryTarget(null);
            setPasswordHistory([]);
          }}
          onCopy={(value) => copyText(value, 'historical password')}
        />
      )}
    </div>
  );
}

function AuthLayout(props) {
  const {
    mode,
    setMode,
    authForm,
    updateAuthForm,
    forgotForm,
    setForgotForm,
    resetPassword,
    setResetPassword,
    onLogin,
    onRegister,
    onForgotVerify,
    onForgotReset,
    loading,
    notice,
  } = props;

  const title = mode === 'register' ? 'Create account'
    : mode === 'forgot' ? 'Recover access'
      : mode === 'reset' ? 'Set new master password'
        : 'Sign in';

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface px-4 py-8">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-slate-900 text-white">
            <KeyRound size={22} />
          </div>
          <div>
            <h1 className="text-2xl font-semibold text-slate-950">Password Manager</h1>
            <p className="text-sm text-slate-500">Production-lite vault demo</p>
          </div>
        </div>

        {notice && <Notice notice={notice} />}

        <section className="panel p-5">
          <h2 className="text-xl font-semibold text-slate-950">{title}</h2>
          <p className="mt-1 text-sm text-slate-500">
            {mode === 'login' && 'Use your master password to unlock the vault.'}
            {mode === 'register' && 'A recovery code set will be generated after registration.'}
            {mode === 'forgot' && 'Verify one recovery code before choosing a new master password.'}
            {mode === 'reset' && 'Choose a new master password. Your vault entries stay preserved.'}
          </p>

          {mode === 'login' && (
            <form className="mt-5 space-y-4" onSubmit={onLogin}>
              <TextField label="Username" value={authForm.username} onChange={(value) => updateAuthForm('username', value)} required />
              <TextField label="Password" type="password" value={authForm.password} onChange={(value) => updateAuthForm('password', value)} required />
              <button className="btn btn-primary w-full" disabled={loading}>{loading ? 'Signing in...' : 'Sign in'}</button>
            </form>
          )}

          {mode === 'register' && (
            <form className="mt-5 space-y-4" onSubmit={onRegister}>
              <TextField label="Username" value={authForm.username} onChange={(value) => updateAuthForm('username', value)} required minLength={3} maxLength={64} />
              <TextField label="Master password" type="password" value={authForm.password} onChange={(value) => updateAuthForm('password', value)} required minLength={10} helper="Minimum 10 characters. 12+ with number, uppercase, and symbol is recommended." />
              <button className="btn btn-primary w-full" disabled={loading}>{loading ? 'Creating...' : 'Create account'}</button>
            </form>
          )}

          {mode === 'forgot' && (
            <form className="mt-5 space-y-4" onSubmit={onForgotVerify}>
              <TextField label="Username" value={forgotForm.username} onChange={(value) => setForgotForm((current) => ({ ...current, username: value }))} required />
              <TextField label="Recovery code" value={forgotForm.recovery_code} onChange={(value) => setForgotForm((current) => ({ ...current, recovery_code: value }))} required />
              <button className="btn btn-primary w-full">Verify recovery code</button>
            </form>
          )}

          {mode === 'reset' && (
            <form className="mt-5 space-y-4" onSubmit={onForgotReset}>
              <TextField label="New master password" type="password" value={resetPassword} onChange={setResetPassword} required minLength={10} />
              <button className="btn btn-primary w-full">Reset password</button>
            </form>
          )}

          <div className="mt-5 flex flex-wrap gap-2 text-sm">
            {mode !== 'login' && <button className="font-medium text-slate-700 hover:text-slate-950" onClick={() => setMode('login')}>Back to sign in</button>}
            {mode === 'login' && <button className="font-medium text-slate-700 hover:text-slate-950" onClick={() => setMode('register')}>Create account</button>}
            {mode === 'login' && <span className="text-slate-300">/</span>}
            {mode === 'login' && <button className="font-medium text-slate-700 hover:text-slate-950" onClick={() => setMode('forgot')}>Forgot password</button>}
          </div>
        </section>
      </div>
    </div>
  );
}

function Sidebar({ section, setSection, open, setOpen }) {
  return (
    <>
      <aside className="hidden w-64 border-r border-slate-200 bg-white lg:block">
        <SidebarContent section={section} setSection={setSection} />
      </aside>
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button className="absolute inset-0 bg-slate-950/40" aria-label="Close navigation" onClick={() => setOpen(false)} />
          <aside className="relative h-full w-72 bg-white shadow-xl">
            <SidebarContent section={section} setSection={setSection} onClose={() => setOpen(false)} />
          </aside>
        </div>
      )}
    </>
  );
}

function SidebarContent({ section, setSection, onClose }) {
  return (
    <div className="flex h-full flex-col p-4">
      <div className="mb-6 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-900 text-white">
            <KeyRound size={20} />
          </div>
          <div>
            <div className="font-semibold text-slate-950">Vault</div>
            <div className="text-xs text-slate-500">Password Manager</div>
          </div>
        </div>
        {onClose && <button className="btn px-2" onClick={onClose}><X size={18} /></button>}
      </div>

      <nav className="space-y-1">
        {navItems.map((item) => {
          const Icon = item.icon;
          const active = section === item.id;
          return (
            <button
              key={item.id}
              className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium transition ${
                active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'
              }`}
              onClick={() => setSection(item.id)}
            >
              <Icon size={18} />
              {item.label}
            </button>
          );
        })}
      </nav>

      <div className="mt-auto rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
        Passwords stay encrypted in the backend. This UI only handles authenticated workflows.
      </div>
    </div>
  );
}

function TopBar({ username, status, notice, onMenu, onLogout, onRefreshStatus }) {
  return (
    <header className="border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:px-8">
      <div className="flex items-center justify-between gap-4">
        <div className="flex min-w-0 items-center gap-3">
          <button className="btn px-2 lg:hidden" onClick={onMenu}><Menu size={18} /></button>
          <div className="min-w-0">
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <User size={15} />
              <span className="truncate">{username || 'Authenticated user'}</span>
            </div>
            <div className="mt-1 text-xs text-slate-400">
              Session timeout: {status?.timeout_seconds ? `${Math.round(status.timeout_seconds / 60)} min` : 'active'}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button className="btn px-2" title="Refresh session status" onClick={onRefreshStatus}><RefreshCw size={17} /></button>
          <button className="btn" onClick={onLogout}><LogOut size={17} /> Logout</button>
        </div>
      </div>
      {notice && <div className="mt-3"><Notice notice={notice} compact /></div>}
    </header>
  );
}

function VaultSection(props) {
  const {
    accounts,
    allStats,
    loading,
    searchTerm,
    setSearchTerm,
    favoritesOnly,
    setFavoritesOnly,
    categoryFilter,
    setCategoryFilter,
    trashMode,
    setTrashMode,
    revealed,
    onSearch,
    onReload,
    onCreate,
    onEdit,
    onDelete,
    onCopy,
    onCopyPassword,
    onReveal,
    onRestore,
    onPermanentDelete,
    onHistory,
  } = props;

  return (
    <section className="space-y-5">
      <SectionHeader
        title="Vault"
        description={trashMode ? 'Restore or permanently remove deleted credentials.' : 'Create, search, copy, and manage saved credentials.'}
        actions={!trashMode && <button className="btn btn-primary" onClick={onCreate}><Plus size={17} /> New entry</button>}
      />

      <div className="grid gap-3 sm:grid-cols-3">
        <StatCard label="Entries" value={allStats.total} />
        <StatCard label="Favorites" value={allStats.favorites} />
        <StatCard label="Categories" value={allStats.categories} />
      </div>

      <div className="panel p-4">
        <form className="flex flex-col gap-3 lg:flex-row" onSubmit={onSearch}>
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3 top-2.5 text-slate-400" size={18} />
            <input className="input pl-10" value={searchTerm} onChange={(event) => setSearchTerm(event.target.value)} placeholder="Search by site, URL, or username" />
          </div>
          <input className="input lg:max-w-44" value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)} placeholder="Category" disabled={trashMode} />
          <label className="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
            <input type="checkbox" checked={favoritesOnly} onChange={(event) => setFavoritesOnly(event.target.checked)} disabled={trashMode} />
            Favorites
          </label>
          <label className="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
            <input type="checkbox" checked={trashMode} onChange={(event) => setTrashMode(event.target.checked)} />
            Trash
          </label>
          <button className="btn" type="submit"><Search size={17} /> Search</button>
          <button className="btn" type="button" onClick={onReload}><RefreshCw size={17} /> Reload</button>
        </form>
      </div>

      <div className="panel overflow-hidden">
        <div className="hidden min-w-full md:block">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Site</th>
                <th className="px-4 py-3">Username</th>
                {!trashMode && <th className="px-4 py-3">Password</th>}
                <th className="px-4 py-3">{trashMode ? 'Deleted' : 'Last used'}</th>
                <th className="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {accounts.map((account) => (
                <tr key={account.id} className="bg-white">
                  <td className="px-4 py-3">
                    <EntryTitle account={account} />
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="max-w-[180px] truncate text-slate-700">{account.username}</span>
                      <IconButton title="Copy username" onClick={() => onCopy(account.username, 'username')}>
                        <Copy size={16} />
                      </IconButton>
                    </div>
                  </td>
                  {!trashMode && (
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <code className="max-w-[180px] truncate rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">
                          {revealed[account.id] || '************'}
                        </code>
                        <IconButton title={revealed[account.id] ? 'Hide password' : 'Reveal password'} onClick={() => onReveal(account)}>
                          {revealed[account.id] ? <EyeOff size={16} /> : <Eye size={16} />}
                        </IconButton>
                        <IconButton title="Copy password" onClick={() => onCopyPassword(account)}>
                          <Copy size={16} />
                        </IconButton>
                      </div>
                    </td>
                  )}
                  <td className="px-4 py-3 text-slate-500">{formatDate(trashMode ? account.deleted_at : account.last_used_at) || (trashMode ? '' : 'Never')}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-2">
                      {trashMode ? (
                        <>
                          <IconButton title="Restore" onClick={() => onRestore(account)}><RefreshCw size={16} /></IconButton>
                          <IconButton title="Delete permanently" onClick={() => onPermanentDelete(account)} danger><Trash2 size={16} /></IconButton>
                        </>
                      ) : (
                        <>
                          <IconButton title="Password history" onClick={() => onHistory(account)}><History size={16} /></IconButton>
                          <IconButton title="Edit" onClick={() => onEdit(account)}><Edit3 size={16} /></IconButton>
                          <IconButton title="Delete" onClick={() => onDelete(account)} danger><Trash2 size={16} /></IconButton>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="divide-y divide-slate-100 md:hidden">
          {accounts.map((account) => (
            <div key={account.id} className="space-y-3 p-4">
              <EntryTitle account={account} />
              <div className="text-sm text-slate-600">{account.username}</div>
              <div className="flex flex-wrap gap-2">
                <button className="btn" onClick={() => onCopy(account.username, 'username')}><Copy size={16} /> Username</button>
                {trashMode ? (
                  <>
                    <button className="btn" onClick={() => onRestore(account)}><RefreshCw size={16} /> Restore</button>
                    <button className="btn btn-danger" onClick={() => onPermanentDelete(account)}><Trash2 size={16} /> Delete forever</button>
                  </>
                ) : (
                  <>
                    <button className="btn" onClick={() => onCopyPassword(account)}><Copy size={16} /> Password</button>
                    <button className="btn" onClick={() => onHistory(account)}><History size={16} /> History</button>
                    <button className="btn" onClick={() => onEdit(account)}><Edit3 size={16} /> Edit</button>
                    <button className="btn btn-danger" onClick={() => onDelete(account)}><Trash2 size={16} /> Delete</button>
                  </>
                )}
              </div>
            </div>
          ))}
        </div>

        {!loading && accounts.length === 0 && (
          <EmptyState title="No vault entries" description="Create your first saved credential or clear the search filter." />
        )}
        {loading && <div className="p-6 text-sm text-slate-500">Loading vault entries...</div>}
      </div>
    </section>
  );
}

function EntryTitle({ account }) {
  return (
    <div>
      <div className="flex items-center gap-2 font-medium text-slate-950">
        {account.favorite ? <Star className="fill-amber-400 text-amber-400" size={16} /> : null}
        <span>{account.site_name}</span>
      </div>
      <div className="mt-1 flex flex-wrap gap-2 text-xs text-slate-500">
        {account.category && <span className="rounded bg-slate-100 px-2 py-0.5">{account.category}</span>}
        {account.site_url && <span className="truncate">{account.site_url}</span>}
        {account.password_updated_at && <span>Updated {formatDate(account.password_updated_at)}</span>}
      </div>
    </div>
  );
}

function EntryModal({ mode, form, setForm, saving, assessment, onClose, onSubmit, onGenerate }) {
  const [showPassword, setShowPassword] = useState(false);
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
      <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
          <div>
            <h2 className="text-lg font-semibold text-slate-950">{mode === 'edit' ? 'Edit entry' : 'New entry'}</h2>
            <p className="text-sm text-slate-500">Store the credential encrypted in your vault.</p>
          </div>
          <button className="btn px-2" onClick={onClose}><X size={18} /></button>
        </div>

        <form className="space-y-4 p-5" onSubmit={onSubmit}>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Site name" value={form.site_name} onChange={(value) => setForm((current) => ({ ...current, site_name: value }))} required maxLength={128} />
            <TextField label="Username" value={form.username} onChange={(value) => setForm((current) => ({ ...current, username: value }))} required maxLength={128} />
          </div>

          <div>
            <label className="label">Password</label>
            <div className="mt-1 flex gap-2">
              <input className="input" type={showPassword ? 'text' : 'password'} value={form.password} onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} required />
              <button type="button" className="btn px-3" onClick={() => setShowPassword((value) => !value)}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button>
              <button type="button" className="btn" onClick={onGenerate}><Wand2 size={17} /> Generate</button>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Site URL" value={form.site_url} onChange={(value) => setForm((current) => ({ ...current, site_url: value }))} />
            <TextField label="Category" value={form.category} onChange={(value) => setForm((current) => ({ ...current, category: value }))} maxLength={64} />
          </div>

          <label className="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" checked={form.favorite} onChange={(event) => setForm((current) => ({ ...current, favorite: event.target.checked }))} />
            Favorite
          </label>

          <div>
            <label className="label">Notes</label>
            <textarea className="input mt-1 min-h-24" value={form.notes} onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))} />
          </div>

          {assessment?.warnings?.length ? (
            <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              <div className="font-medium">Password warnings</div>
              <ul className="mt-1 list-disc pl-5">
                {assessment.warnings.map((warning) => <li key={warning}>{warning}</li>)}
              </ul>
            </div>
          ) : null}

          <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
            <button type="button" className="btn" onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" disabled={saving}>{saving ? 'Saving...' : 'Save entry'}</button>
          </div>
        </form>
      </div>
    </div>
  );
}

function GeneratorSection({ form, setForm, generatedPassword, loading, onGenerate, onCopy, onUseInEntry }) {
  const selectedGroups = ['uppercase', 'lowercase', 'numbers', 'symbols'].filter((key) => form[key]).length;
  return (
    <section className="space-y-5">
      <SectionHeader title="Generator" description="Generate strong passwords without saving them automatically." />
      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <form className="panel space-y-5 p-5" onSubmit={onGenerate}>
          <div>
            <label className="label">Length: {form.length}</label>
            <input className="mt-3 w-full accent-slate-900" type="range" min="8" max="128" value={form.length} onChange={(event) => setForm((current) => ({ ...current, length: Number(event.target.value) }))} />
            <input className="input mt-3 max-w-32" type="number" min="8" max="128" value={form.length} onChange={(event) => setForm((current) => ({ ...current, length: Number(event.target.value) }))} />
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            {['uppercase', 'lowercase', 'numbers', 'symbols'].map((key) => (
              <label key={key} className="flex items-center gap-2 rounded-md border border-slate-200 p-3 text-sm font-medium capitalize text-slate-700">
                <input
                  type="checkbox"
                  checked={form[key]}
                  onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.checked }))}
                />
                {key}
              </label>
            ))}
          </div>

          <button className="btn btn-primary" disabled={loading || selectedGroups === 0}><Wand2 size={17} /> {loading ? 'Generating...' : 'Generate password'}</button>
        </form>

        <div className="panel p-5">
          <h3 className="font-semibold text-slate-950">Generated password</h3>
          <code className="mt-4 block min-h-16 break-all rounded-md bg-slate-100 p-3 text-sm text-slate-800">
            {generatedPassword || 'Generate a password to preview it here.'}
          </code>
          <div className="mt-4 flex flex-wrap gap-2">
            <button className="btn" disabled={!generatedPassword} onClick={() => onCopy(generatedPassword)}><Copy size={17} /> Copy</button>
            <button className="btn btn-primary" disabled={!generatedPassword} onClick={onUseInEntry}><Plus size={17} /> Use in new entry</button>
          </div>
        </div>
      </div>
    </section>
  );
}

function BackupSection({ backupPassword, setBackupPassword, restoreFile, setRestoreFile, history, loading, onCreate, onRestore, onReload }) {
  return (
    <section className="space-y-5">
      <SectionHeader title="Backup" description="Create encrypted backups and restore entries from a backup file." actions={<button className="btn" onClick={onReload}><RefreshCw size={17} /> Reload history</button>} />
      <div className="grid gap-5 lg:grid-cols-2">
        <div className="panel space-y-4 p-5">
          <h3 className="font-semibold text-slate-950">Create backup</h3>
          <TextField label="Backup password" type="password" value={backupPassword} onChange={setBackupPassword} helper="Required for both backup creation and restore." />
          <button className="btn btn-primary" disabled={loading} onClick={onCreate}><DatabaseBackup size={17} /> Create encrypted backup</button>
        </div>

        <form className="panel space-y-4 p-5" onSubmit={onRestore}>
          <h3 className="font-semibold text-slate-950">Restore backup</h3>
          <label className="block">
            <span className="label">Backup JSON file</span>
            <input className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" type="file" accept=".json,application/json" onChange={(event) => setRestoreFile(event.target.files?.[0] || null)} />
          </label>
          {restoreFile && <div className="text-sm text-slate-500">Selected: {restoreFile.name}</div>}
          <button className="btn btn-primary" disabled={loading}><Upload size={17} /> Restore from file</button>
        </form>
      </div>

      <div className="panel overflow-hidden">
        <div className="border-b border-slate-200 px-5 py-4">
          <h3 className="font-semibold text-slate-950">Backup history</h3>
        </div>
        {history.length > 0 ? (
          <div className="divide-y divide-slate-100">
            {history.map((item) => (
              <div key={item.id || item.filename} className="flex flex-col gap-1 px-5 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                <span className="font-medium text-slate-800">{item.filename}</span>
                <span className="text-slate-500">{formatDate(item.created_at || item.restored_at)}</span>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState title="No backup history" description="Create a backup to see it listed here." />
        )}
      </div>
    </section>
  );
}

function RecoverySection({ password, setPassword, codes, onGenerate }) {
  return (
    <section className="space-y-5">
      <SectionHeader title="Recovery" description="Generate one-time recovery codes for master password reset." />
      <div className="grid gap-5 lg:grid-cols-[420px_minmax(0,1fr)]">
        <form className="panel space-y-4 p-5" onSubmit={onGenerate}>
          <TextField label="Current master password" type="password" value={password} onChange={setPassword} required />
          <button className="btn btn-primary"><Shield size={17} /> Generate new codes</button>
          <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            Recovery codes are shown only once. Store them outside this application.
          </div>
        </form>

        <div className="panel p-5">
          <h3 className="font-semibold text-slate-950">Current generated codes</h3>
          {codes.length > 0 ? (
            <div className="mt-4 grid gap-2 sm:grid-cols-2">
              {codes.map((code) => <code className="rounded-md bg-slate-100 px-3 py-2 text-sm text-slate-800" key={code}>{code}</code>)}
            </div>
          ) : (
            <p className="mt-3 text-sm text-slate-500">No recovery codes are visible right now. Generate a new set to display them once.</p>
          )}
        </div>
      </div>
    </section>
  );
}

function SecuritySection({ events, report, loading, onReload }) {
  return (
    <section className="space-y-5">
      <SectionHeader title="Security" description="Recent security-relevant events for this account." actions={<button className="btn" onClick={onReload}><RefreshCw size={17} /> Reload</button>} />
      {report && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard label="Entries" value={report.total ?? 0} />
          <StatCard label="Weak" value={report.weak_count ?? 0} />
          <StatCard label="Reused" value={report.reused_count ?? 0} />
          <StatCard label="Old 90d+" value={report.old_password_count ?? 0} />
          <StatCard label="Missing URL" value={report.missing_url_count ?? 0} />
        </div>
      )}
      <div className="panel overflow-hidden">
        {loading && <div className="p-6 text-sm text-slate-500">Loading security events...</div>}
        {!loading && events.length === 0 && <EmptyState title="No security events" description="Events will appear here after auth, backup, and recovery actions." />}
        {!loading && events.length > 0 && (
          <div className="divide-y divide-slate-100">
            {events.map((event) => (
              <div key={event.id} className="grid gap-2 px-5 py-4 text-sm lg:grid-cols-[220px_minmax(0,1fr)_220px]">
                <div>
                  <div className="font-medium text-slate-950">{event.event_type}</div>
                  <div className="text-xs text-slate-500">{formatDate(event.created_at)}</div>
                </div>
                <div className="text-slate-600">{metadataSummary(event.metadata)}</div>
                <div className="text-xs text-slate-500 lg:text-right">{event.ip_address || 'unknown IP'}</div>
              </div>
            ))}
          </div>
        )}
      </div>
    </section>
  );
}

function SettingsSection({ username, status, passwordForm, setPasswordForm, loading, sessions, onChangePassword, onLogout, onLogoutAll, onRefreshStatus, onReloadSessions, onRevokeSession }) {
  return (
    <section className="space-y-5">
      <SectionHeader title="Settings" description="Account and session controls." actions={<button className="btn" onClick={onRefreshStatus}><RefreshCw size={17} /> Refresh status</button>} />
      <div className="grid gap-5 lg:grid-cols-2">
        <form className="panel space-y-4 p-5" onSubmit={onChangePassword}>
          <h3 className="font-semibold text-slate-950">Change master password</h3>
          <TextField label="Current password" type="password" value={passwordForm.current_password} onChange={(value) => setPasswordForm((current) => ({ ...current, current_password: value }))} required />
          <TextField label="New password" type="password" value={passwordForm.new_password} onChange={(value) => setPasswordForm((current) => ({ ...current, new_password: value }))} required minLength={10} helper="Minimum 10 characters. 12+ is recommended." />
          <button className="btn btn-primary" disabled={loading}>Update master password</button>
        </form>

        <div className="panel space-y-4 p-5">
          <h3 className="font-semibold text-slate-950">Session</h3>
          <div className="rounded-md bg-slate-50 p-3 text-sm text-slate-600">
            <div><span className="font-medium text-slate-800">Username:</span> {username}</div>
            <div><span className="font-medium text-slate-800">Idle:</span> {status?.idle_seconds ?? 0}s</div>
            <div><span className="font-medium text-slate-800">Timeout:</span> {status?.timeout_seconds ?? 0}s</div>
          </div>
          <div className="flex flex-wrap gap-2">
            <button className="btn" onClick={onLogout}><LogOut size={17} /> Logout current session</button>
            <button className="btn btn-danger" onClick={onLogoutAll} disabled={loading}>Logout all sessions</button>
          </div>
        </div>
      </div>
      <div className="panel overflow-hidden">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
          <h3 className="font-semibold text-slate-950">Active sessions</h3>
          <button className="btn" onClick={onReloadSessions}><RefreshCw size={17} /> Reload</button>
        </div>
        {sessions.length === 0 ? (
          <EmptyState title="No sessions" description="Session records will appear after login." />
        ) : (
          <div className="divide-y divide-slate-100">
            {sessions.map((session) => (
              <div key={session.id} className="grid gap-3 px-5 py-4 text-sm lg:grid-cols-[minmax(0,1fr)_180px_140px] lg:items-center">
                <div>
                  <div className="font-medium text-slate-950">
                    {session.current ? 'Current session' : 'Other session'} {session.revoked_at ? '(revoked)' : ''}
                  </div>
                  <div className="mt-1 truncate text-xs text-slate-500">{session.user_agent || 'Unknown browser'}</div>
                  <div className="mt-1 text-xs text-slate-500">{session.ip_address || 'unknown IP'}</div>
                </div>
                <div className="text-xs text-slate-500">Last seen {formatDate(session.last_seen_at)}</div>
                <button className="btn btn-danger" disabled={Boolean(session.revoked_at)} onClick={() => onRevokeSession(session)}>Revoke</button>
              </div>
            ))}
          </div>
        )}
      </div>
    </section>
  );
}

function SectionHeader({ title, description, actions }) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h2 className="text-2xl font-semibold text-slate-950">{title}</h2>
        <p className="mt-1 text-sm text-slate-500">{description}</p>
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

function StatCard({ label, value }) {
  return (
    <div className="panel p-4">
      <div className="text-sm text-slate-500">{label}</div>
      <div className="mt-1 text-2xl font-semibold text-slate-950">{value}</div>
    </div>
  );
}

function TextField({ label, value, onChange, type = 'text', helper, ...props }) {
  return (
    <label className="block">
      <span className="label">{label}</span>
      <input className="input mt-1" type={type} value={value} onChange={(event) => onChange(event.target.value)} {...props} />
      {helper && <span className="mt-1 block text-xs text-slate-500">{helper}</span>}
    </label>
  );
}

function Notice({ notice, compact = false }) {
  const style = notice.type === 'error'
    ? 'border-red-200 bg-red-50 text-red-800'
    : notice.type === 'success'
      ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
      : 'border-slate-200 bg-slate-50 text-slate-700';
  const Icon = notice.type === 'error' ? AlertTriangle : CheckCircle2;
  return (
    <div className={`flex items-start gap-2 rounded-md border ${style} ${compact ? 'px-3 py-2 text-sm' : 'mb-4 px-4 py-3 text-sm'}`}>
      <Icon className="mt-0.5 shrink-0" size={16} />
      <span>{notice.text}</span>
    </div>
  );
}

function IconButton({ title, children, onClick, danger = false }) {
  return (
    <button
      type="button"
      title={title}
      aria-label={title}
      onClick={onClick}
      className={`inline-flex h-8 w-8 items-center justify-center rounded-md border text-sm transition ${
        danger ? 'border-red-200 text-red-600 hover:bg-red-50' : 'border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-950'
      }`}
    >
      {children}
    </button>
  );
}

function EmptyState({ title, description }) {
  return (
    <div className="p-8 text-center">
      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
        <KeyRound size={22} />
      </div>
      <h3 className="mt-3 font-semibold text-slate-950">{title}</h3>
      <p className="mt-1 text-sm text-slate-500">{description}</p>
    </div>
  );
}

function ConfirmModal({ title, body, confirmLabel, onCancel, onConfirm }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
      <div className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
        <p className="mt-2 text-sm text-slate-600">{body}</p>
        <div className="mt-5 flex justify-end gap-2">
          <button className="btn" onClick={onCancel}>Cancel</button>
          <button className="btn btn-danger" onClick={onConfirm}>{confirmLabel}</button>
        </div>
      </div>
    </div>
  );
}

function PasswordHistoryModal({ account, rows, onClose, onCopy }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
      <div className="w-full max-w-2xl rounded-lg bg-white p-5 shadow-xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold text-slate-950">Password history</h2>
            <p className="mt-1 text-sm text-slate-500">{account.site_name}</p>
          </div>
          <button className="btn px-2" onClick={onClose}><X size={18} /></button>
        </div>
        <div className="mt-5 divide-y divide-slate-100 rounded-md border border-slate-200">
          {rows.length === 0 ? (
            <div className="p-4 text-sm text-slate-500">No previous passwords for this entry.</div>
          ) : rows.map((row) => (
            <div key={row.id} className="grid gap-3 p-4 text-sm md:grid-cols-[minmax(0,1fr)_180px_auto] md:items-center">
              <code className="break-all rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">{row.password}</code>
              <span className="text-slate-500">{formatDate(row.password_updated_at || row.created_at)}</span>
              <button className="btn" onClick={() => onCopy(row.password)}><Copy size={16} /> Copy</button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function formatDate(value) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString();
}

function metadataSummary(metadata) {
  if (!metadata || Object.keys(metadata).length === 0) return 'No metadata';
  return Object.entries(metadata).map(([key, value]) => `${key}: ${String(value)}`).join(', ');
}
