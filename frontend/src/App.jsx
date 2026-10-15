import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { GuestOnly, RequireAuth } from './auth/Guards.jsx';
import { homeFor, useAuth } from './auth/AuthContext.jsx';
import { PublicLayout } from './layouts/PublicLayout.jsx';
import { AppShell } from './layouts/AppShell.jsx';
import { Loading } from './components/ui/Display.jsx';

import Home from './pages/public/Home.jsx';
import Courses from './pages/public/Courses.jsx';
import CourseDetail from './pages/public/CourseDetail.jsx';
import { Lecturers, LecturerProfile } from './pages/public/Lecturers.jsx';
import { About, Faq, Pricing, Terms, Privacy, Refunds, Support, NotFound } from './pages/public/Static.jsx';
import Contact from './pages/public/Contact.jsx';
import VerifyCertificate from './pages/public/VerifyCertificate.jsx';
import { Login, Register, Forgot, ResetPassword, VerifyEmailResult } from './pages/public/Auth.jsx';

const lazyPage = (loader, name) => lazy(() => loader().then((m) => ({ default: m[name] || m.default })));
const Student = {
  Dashboard: lazyPage(() => import('./pages/student/Dashboard.jsx'), 'Dashboard'),
  Courses: lazyPage(() => import('./pages/student/MyCourses.jsx'), 'MyCourses'),
  CourseHome: lazyPage(() => import('./pages/student/CourseHome.jsx'), 'CourseHome'),
  Lesson: lazyPage(() => import('./pages/student/LessonPlayer.jsx'), 'LessonPlayer'),
  Attempt: lazyPage(() => import('./pages/student/AttemptPage.jsx'), 'AttemptPage'),
  Thread: lazyPage(() => import('./pages/student/ThreadPage.jsx'), 'ThreadPage'),
  Results: lazyPage(() => import('./pages/student/Results.jsx'), 'Results'),
  Certificates: lazyPage(() => import('./pages/student/Certificates.jsx'), 'Certificates'),
  Billing: lazyPage(() => import('./pages/student/Billing.jsx'), 'Billing'),
  Checkout: lazyPage(() => import('./pages/student/CheckoutCallback.jsx'), 'CheckoutCallback'),
  Support: lazyPage(() => import('./pages/student/SupportPage.jsx'), 'SupportPage'),
};
const Shared = {
  Notifications: lazyPage(() => import('./pages/student/Notifications.jsx'), 'Notifications'),
  Account: lazyPage(() => import('./pages/student/Account.jsx'), 'Account'),
  MfaSetup: lazyPage(() => import('./pages/student/MfaSetup.jsx'), 'MfaSetup'),
};
const Lecturer = {
  Dashboard: lazyPage(() => import('./pages/lecturer/Dashboard.jsx'), 'LecturerDashboard'),
  Courses: lazyPage(() => import('./pages/lecturer/Courses.jsx'), 'LecturerCourses'),
  Editor: lazyPage(() => import('./pages/lecturer/CourseEditor.jsx'), 'CourseEditor'),
  Grading: lazyPage(() => import('./pages/lecturer/Grading.jsx'), 'Grading'),
  Marking: lazyPage(() => import('./pages/lecturer/Marking.jsx'), 'Marking'),
};
const Admin = {
  Dashboard: lazyPage(() => import('./pages/admin/Dashboard.jsx'), 'AdminDashboard'),
  Users: lazyPage(() => import('./pages/admin/Users.jsx'), 'Users'),
  Roles: lazyPage(() => import('./pages/admin/Roles.jsx'), 'Roles'),
  Courses: lazyPage(() => import('./pages/admin/Courses.jsx'), 'AdminCourses'),
  Categories: lazyPage(() => import('./pages/admin/Categories.jsx'), 'Categories'),
  Enrolments: lazyPage(() => import('./pages/admin/Enrolments.jsx'), 'Enrolments'),
  Certificates: lazyPage(() => import('./pages/admin/Certificates.jsx'), 'AdminCertificates'),
  Reports: lazyPage(() => import('./pages/admin/Reports.jsx'), 'Reports'),
  Finance: lazyPage(() => import('./pages/admin/Finance.jsx'), 'Finance'),
  Support: lazyPage(() => import('./pages/admin/SupportDesk.jsx'), 'SupportDesk'),
  Notifications: lazyPage(() => import('./pages/admin/NotificationsAdmin.jsx'), 'NotificationsAdmin'),
  Audit: lazyPage(() => import('./pages/admin/Audit.jsx'), 'Audit'),
  System: lazyPage(() => import('./pages/admin/System.jsx'), 'System'),
};

function Landing() { const { user } = useAuth(); return <Navigate to={homeFor(user)} replace />; }

export default function App() {
  return (
    <Suspense fallback={<div className="container band"><Loading label="Loading page" /></div>}>
      <Routes>
        <Route element={<PublicLayout />}>
          <Route index element={<Home />} />
          <Route path="courses" element={<Courses />} />
          <Route path="courses/:slug" element={<CourseDetail />} />
          <Route path="lecturers" element={<Lecturers />} />
          <Route path="lecturers/:id" element={<LecturerProfile />} />
          <Route path="pricing" element={<Pricing />} />
          <Route path="about" element={<About />} />
          <Route path="faq" element={<Faq />} />
          <Route path="contact" element={<Contact />} />
          <Route path="support" element={<Support />} />
          <Route path="terms" element={<Terms />} />
          <Route path="privacy" element={<Privacy />} />
          <Route path="refunds" element={<Refunds />} />
          <Route path="verify" element={<VerifyCertificate />} />
          <Route path="verify/:code" element={<VerifyCertificate />} />
          <Route path="login" element={<GuestOnly><Login /></GuestOnly>} />
          <Route path="register" element={<GuestOnly><Register /></GuestOnly>} />
          <Route path="forgot-password" element={<GuestOnly><Forgot /></GuestOnly>} />
          <Route path="reset-password" element={<GuestOnly><ResetPassword /></GuestOnly>} />
          <Route path="verify-email" element={<VerifyEmailResult />} />
          <Route path="mfa-setup" element={<RequireAuth><Shared.MfaSetup /></RequireAuth>} />
          <Route path="checkout/callback" element={<RequireAuth><Student.Checkout /></RequireAuth>} />
          <Route path="home" element={<Landing />} />
          <Route path="*" element={<NotFound />} />
        </Route>

        <Route path="student" element={<RequireAuth><AppShell area="student" /></RequireAuth>}>
          <Route index element={<Student.Dashboard />} />
          <Route path="courses" element={<Student.Courses />} />
          <Route path="courses/:courseId" element={<Student.CourseHome />} />
          <Route path="courses/:courseId/lessons/:lessonId" element={<Student.Lesson />} />
          <Route path="courses/:courseId/discussions/:threadId" element={<Student.Thread />} />
          <Route path="attempts/:attemptId" element={<Student.Attempt />} />
          <Route path="results" element={<Student.Results />} />
          <Route path="certificates" element={<Student.Certificates />} />
          <Route path="billing" element={<Student.Billing />} />
          <Route path="notifications" element={<Shared.Notifications />} />
          <Route path="support" element={<Student.Support />} />
        </Route>

        <Route path="lecturer" element={<RequireAuth permissions={['courses.manage-own', 'courses.manage-all']}><AppShell area="lecturer" /></RequireAuth>}>
          <Route index element={<Lecturer.Dashboard />} />
          <Route path="courses" element={<Lecturer.Courses />} />
          <Route path="courses/:courseId" element={<Lecturer.Editor />} />
          <Route path="assignments/:assignmentId" element={<Lecturer.Grading />} />
          <Route path="assessments/:assessmentId" element={<Lecturer.Marking />} />
          <Route path="notifications" element={<Shared.Notifications />} />
        </Route>

        <Route path="admin" element={<RequireAuth roles={['admin', 'finance']}><AppShell area="admin" /></RequireAuth>}>
          <Route index element={<Admin.Dashboard />} />
          <Route path="users" element={<Admin.Users />} />
          <Route path="roles" element={<Admin.Roles />} />
          <Route path="courses" element={<Admin.Courses />} />
          <Route path="categories" element={<Admin.Categories />} />
          <Route path="enrolments" element={<Admin.Enrolments />} />
          <Route path="certificates" element={<Admin.Certificates />} />
          <Route path="reports" element={<Admin.Reports />} />
          <Route path="finance/*" element={<Admin.Finance />} />
          <Route path="support" element={<Admin.Support />} />
          <Route path="notifications" element={<Admin.Notifications />} />
          <Route path="audit" element={<Admin.Audit />} />
          <Route path="system" element={<Admin.System />} />
        </Route>

        {/* Shared account area uses whichever shell fits the user's main role */}
        <Route path="account" element={<RequireAuth><AccountShell /></RequireAuth>}>
          <Route index element={<Shared.Account />} />
        </Route>
      </Routes>
    </Suspense>
  );
}

function AccountShell() {
  const { user } = useAuth();
  const area = user.roles.includes('admin') || user.roles.includes('finance') ? 'admin' : user.roles.includes('lecturer') ? 'lecturer' : 'student';
  return <AppShell area={area} />;
}
