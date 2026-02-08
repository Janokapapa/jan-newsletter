import { useState } from 'react';
import Dashboard from './pages/Dashboard';
import Subscribers from './pages/Subscribers';
import Lists from './pages/Lists';
import Campaigns from './pages/Campaigns';
import CampaignEditor from './pages/CampaignEditor';
import Queue from './pages/Queue';
import Logs from './pages/Logs';
import SettingsPage from './pages/Settings';

type Page =
  | 'dashboard'
  | 'subscribers'
  | 'lists'
  | 'campaigns'
  | 'campaign-editor'
  | 'queue'
  | 'logs'
  | 'settings';

export default function App() {
  // Get current page from WordPress
  const wpPage = window.janNewsletter?.currentPage || 'dashboard';

  // Check URL for campaign editor mode
  const urlParams = new URLSearchParams(window.location.search);
  const campaignId = urlParams.get('campaign_id');
  const isEditing = urlParams.get('action') === 'edit';

  const [currentPage, setCurrentPage] = useState<Page>(
    isEditing && campaignId ? 'campaign-editor' : (wpPage as Page)
  );
  const [editingCampaignId, setEditingCampaignId] = useState<number | null>(
    campaignId ? parseInt(campaignId, 10) : null
  );

  const handleEditCampaign = (id: number | null) => {
    setEditingCampaignId(id);
    setCurrentPage('campaign-editor');

    // Update URL without page reload
    const url = new URL(window.location.href);
    if (id) {
      url.searchParams.set('campaign_id', id.toString());
      url.searchParams.set('action', 'edit');
    }
    window.history.pushState({}, '', url.toString());
  };

  const handleBackFromEditor = () => {
    setEditingCampaignId(null);
    setCurrentPage('campaigns');

    // Go back to campaigns page
    window.location.href = window.janNewsletter.menuUrls.campaigns;
  };

  const renderPage = () => {
    switch (currentPage) {
      case 'dashboard':
        return <Dashboard />;
      case 'subscribers':
        return <Subscribers />;
      case 'lists':
        return <Lists />;
      case 'campaigns':
        return <Campaigns onEditCampaign={handleEditCampaign} />;
      case 'campaign-editor':
        return <CampaignEditor campaignId={editingCampaignId} onBack={handleBackFromEditor} />;
      case 'queue':
        return <Queue />;
      case 'logs':
        return <Logs />;
      case 'settings':
        return <SettingsPage />;
      default:
        return <Dashboard />;
    }
  };

  return (
    <div className="jan-newsletter-app bg-gray-50 min-h-[calc(100vh-100px)] -ml-5 p-6">
      {renderPage()}
    </div>
  );
}
